<?php

namespace App\Services;

use App\Jobs\SendRubikaNotification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class RubikaService
{
    private const LINK_TTL_MINUTES = 10;

    public function connectionUrl(User $user): string
    {
        $username = ltrim((string) config('services.rubika.username'), '@');

        if (!preg_match('/^[a-zA-Z0-9_]{5,64}$/', $username)) {
            throw new RuntimeException('Rubika bot username is not configured.');
        }

        $token = Str::random(40);
        Cache::put($this->linkKey($token), $user->getKey(), now()->addMinutes(self::LINK_TTL_MINUTES));

        return 'https://rubika.ir/'.$username.'?st='.$token;
    }

    public function connectionFor(User $user): ?object
    {
        return DB::table('connections')
            ->where('user_id', $user->getKey())
            ->where('provider', 'rubika')
            ->first();
    }

    public function disconnect(User $user): void
    {
        DB::table('connections')
            ->where('user_id', $user->getKey())
            ->where('provider', 'rubika')
            ->update(['status' => 'disabled', 'updated_at' => now()]);
    }

    public function handleWebhook(array $payload): void
    {
        $update = $payload['update'] ?? null;
        if (!is_array($update)) {
            return;
        }

        $chatId = $update['chat_id'] ?? null;
        if (!is_string($chatId) || $chatId === '' || strlen($chatId) > 255) {
            return;
        }

        if (($update['type'] ?? null) === 'StoppedBot') {
            DB::table('connections')
                ->where('provider', 'rubika')
                ->where('external_id', $chatId)
                ->update(['status' => 'blocked', 'updated_at' => now()]);

            return;
        }

        if (!in_array($update['type'] ?? null, ['StartedBot', 'NewMessage'], true)) {
            return;
        }

        $message = is_array($update['new_message'] ?? null) ? $update['new_message'] : [];
        $startId = $message['aux_data']['start_id']
            ?? $update['aux_data']['start_id']
            ?? null;

        if (!is_string($startId) || !preg_match('/^[A-Za-z0-9]{40}$/', $startId)) {
            return;
        }

        $cacheKey = $this->linkKey($startId);

        Cache::lock('rubika:link:'.hash('sha256', $startId), 30)->block(3, function () use ($cacheKey, $chatId, $message): void {
            $userId = Cache::get($cacheKey);
            if (!is_numeric($userId) || (int) $userId <= 0) {
                return;
            }

            // Rubika chat IDs are strings; never assume chat_id === sender_id.
            $chat = $this->getChat($chatId);
            if (($chat['chat_type'] ?? null) !== 'User') {
                return;
            }
            $senderId = $message['sender_id'] ?? null;
            if (is_string($senderId) && $senderId !== '' && isset($chat['user_id'])
                && $senderId !== (string) $chat['user_id']) {
                return;
            }

            DB::transaction(function () use ($userId, $chatId): void {
                // Lock the owner to serialize reconnects for the same Hub user.
                if (!DB::table('users')->where('id', $userId)->lockForUpdate()->exists()) {
                    return;
                }

                $claimed = DB::table('connections')
                    ->where('provider', 'rubika')
                    ->where('external_id', $chatId)
                    ->where('user_id', '<>', $userId)
                    ->exists();

                if ($claimed) {
                    return;
                }

                $existing = DB::table('connections')
                    ->where('user_id', $userId)
                    ->where('provider', 'rubika')
                    ->lockForUpdate()
                    ->first();

                $now = now();
                if ($existing) {
                    DB::table('connections')->where('id', $existing->id)->update([
                        'external_id' => $chatId,
                        'status' => 'active',
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('connections')->insert([
                        'user_id' => $userId,
                        'provider' => 'rubika',
                        'external_id' => $chatId,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

            // Only consume a valid token when the connection now belongs to this account.
            $linked = DB::table('connections')
                ->where('user_id', $userId)
                ->where('provider', 'rubika')
                ->where('external_id', $chatId)
                ->where('status', 'active')
                ->exists();

            if (!$linked) {
                return;
            }

            Cache::forget($cacheKey);

            try {
                $this->sendMessage($chatId, 'حساب روبیکای شما به Notification Hub متصل شد.');
            } catch (\Throwable) {
                // Link is committed; failed welcome messages must not replay the token.
            }
        });
    }

    public function getChat(string $chatId): array
    {
        $data = $this->request('getChat', ['chat_id' => $chatId]);
        $chat = $data['chat'] ?? null;

        if (!is_array($chat)) {
            throw new RubikaApiException('Rubika returned an invalid chat response.', 0, true);
        }

        return $chat;
    }

    public function sendMessage(string $chatId, string $text): array
    {
        if ($chatId === '' || trim($text) === '') {
            throw new InvalidArgumentException('Rubika chat and message are required.');
        }

        return $this->request('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    }

    public function request(string $method, array $body): array
    {
        $token = (string) config('services.rubika.token');
        if ($token === '') {
            throw new RuntimeException('Rubika bot token is not configured.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->post('https://botapi.rubika.ir/v3/'.$token.'/'.$method, $body);

        $payload = $response->json();
        $httpStatus = $response->status();
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;

        if (!$response->successful() || $status !== 'OK') {
            $retryAfter = max(0, (int) $response->header('Retry-After', 0));
            $retryable = $httpStatus === 429 || $httpStatus >= 500;
            throw new RubikaApiException('Rubika API request failed.', $httpStatus, $retryable, $retryAfter);
        }

        // Some successful Rubika methods have no data payload.
        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    public function queueDelivery(int $notificationId, int $connectionId): int
    {
        $notification = DB::table('notifications')
            ->where('id', $notificationId)->whereNull('deleted_at')->first();
        $connection = DB::table('connections')->where('id', $connectionId)->first();

        if (!$notification || !$connection || (int) $notification->user_id !== (int) $connection->user_id
            || $connection->provider !== 'rubika' || $connection->status !== 'active') {
            throw new InvalidArgumentException('Invalid notification or Rubika connection.');
        }

        DB::table('notification_deliveries')->insertOrIgnore([
            'notification_id' => $notificationId,
            'connection_id' => $connectionId,
            'channel' => 'rubika',
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $delivery = DB::table('notification_deliveries')
            ->where('notification_id', $notificationId)
            ->where('connection_id', $connectionId)->first();

        if ($delivery->status === 'pending') {
            $priority = max(1, min(5, (int) $notification->priority));
            $queue = [1 => 'critical', 2 => 'urgent', 3 => 'high', 4 => 'normal', 5 => 'low'][$priority];
            $pending = SendRubikaNotification::dispatch((int) $delivery->id)->onQueue($queue);

            if ($notification->scheduled_at && Carbon::parse($notification->scheduled_at)->isFuture()) {
                $pending->delay(Carbon::parse($notification->scheduled_at));
            }
        }

        return (int) $delivery->id;
    }

    private function linkKey(string $token): string
    {
        return 'rubika:link:'.hash('sha256', $token);
    }
}
