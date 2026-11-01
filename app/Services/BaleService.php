<?php

namespace App\Services;

use App\Jobs\SendBaleNotification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class BaleService
{
    private const LINK_TTL_MINUTES = 10;

    public function connectionUrl(User $user): string
    {
        $username = ltrim((string) config('services.bale.username'), '@');
        if (!preg_match('/^[a-zA-Z0-9_]{5,32}$/', $username)) {
            throw new RuntimeException('Bale bot username is not configured.');
        }

        $token = Str::random(40);
        Cache::put($this->linkKey($token), $user->getKey(), now()->addMinutes(self::LINK_TTL_MINUTES));

        return 'https://ble.ir/'.$username.'?start='.$token;
    }

    public function connectionFor(User $user): ?object
    {
        return DB::table('connections')
            ->where('user_id', $user->getKey())
            ->where('provider', 'bale')
            ->first();
    }

    public function disconnect(User $user): bool
    {
        return DB::table('connections')
            ->where('user_id', $user->getKey())
            ->where('provider', 'bale')
            ->update(['status' => 'disabled', 'updated_at' => now()]) > 0;
    }

    public function handleWebhook(array $update): void
    {
        $message = $update['message'] ?? null;
        if (!is_array($message) || ($message['chat']['type'] ?? null) !== 'private') {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        if (!preg_match('/^\/start\s+([a-zA-Z0-9]{40})$/', $text, $match)) {
            return;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $senderId = (string) ($message['from']['id'] ?? '');
        if ($chatId === '' || $senderId === '' || $chatId !== $senderId || !ctype_digit($chatId)) {
            return;
        }

        $key = $this->linkKey($match[1]);
        $linked = Cache::lock('bale:lock:'.hash('sha256', $match[1]), 10)->block(3, function () use ($key, $chatId): bool {
            $userId = Cache::get($key);
            if (!is_numeric($userId) || (int) $userId <= 0) {
                return false;
            }

            DB::transaction(function () use ($userId, $chatId): void {
                // Serialize reconnections for one user without limiting other providers.
                if (!DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id'])) {
                    throw new InvalidArgumentException('User no longer exists.');
                }

                // A Bale private chat may only belong to one Hub account.
                $claimed = DB::table('connections')
                    ->where('provider', 'bale')
                    ->where('external_id', $chatId)
                    ->where('user_id', '<>', $userId)
                    ->exists();
                if ($claimed) {
                    throw new InvalidArgumentException('This Bale chat is already connected.');
                }

                $existing = DB::table('connections')
                    ->where('provider', 'bale')
                    ->where('user_id', $userId)
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
                        'provider' => 'bale',
                        'external_id' => $chatId,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

            Cache::forget($key);
            return true;
        });

        if ($linked) {
            try {
                $this->sendMessage($chatId, 'حساب بله شما به Notification Hub متصل شد.');
            } catch (\Throwable $e) {
                // The connection is committed; do not replay the link on welcome failure.
                \Illuminate\Support\Facades\Log::warning('Bale welcome message could not be sent.');
            }
        }
    }

    public function sendMessage(string $chatId, string $text): array
    {
        $token = (string) config('services.bale.token');
        if ($token === '') {
            throw new RuntimeException('Bale bot token is not configured.');
        }
        if ($text === '' || mb_strlen($text) > 4096) {
            throw new InvalidArgumentException('Bale message must contain 1-4096 characters.');
        }

        $response = Http::acceptJson()->timeout(15)->post(
            'https://tapi.bale.ai/bot'.$token.'/sendMessage',
            ['chat_id' => $chatId, 'text' => $text],
        );

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new RuntimeException('Unexpected Bale API response.');
        }

        if ($response->failed() || ($payload['ok'] ?? false) !== true) {
            $code = (int) ($payload['error_code'] ?? $response->status());
            $retryAfter = (int) ($payload['parameters']['retry_after'] ?? $response->header('Retry-After') ?? 0);
            throw new BaleSendException(
                'Bale API failed: '.($payload['description'] ?? 'HTTP '.$code),
                $code,
                $retryAfter,
                $code === 429 || $code >= 500,
            );
        }

        return $payload;
    }

    public function queueDelivery(int $notificationId, int $connectionId): int
    {
        $notification = DB::table('notifications')->where('id', $notificationId)->whereNull('deleted_at')->first();
        $connection = DB::table('connections')->where('id', $connectionId)->first();
        if (!$notification || !$connection || (int) $notification->user_id !== (int) $connection->user_id
            || $connection->provider !== 'bale' || $connection->status !== 'active') {
            throw new InvalidArgumentException('Invalid notification or Bale connection.');
        }

        DB::table('notification_deliveries')->insertOrIgnore([
            'notification_id' => $notificationId,
            'connection_id' => $connectionId,
            'channel' => 'bale',
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $delivery = DB::table('notification_deliveries')
            ->where('notification_id', $notificationId)
            ->where('connection_id', $connectionId)
            ->first();

        if ($delivery->status === 'pending') {
            $priority = max(1, min(5, (int) $notification->priority));
            $queue = [1 => 'critical', 2 => 'urgent', 3 => 'high', 4 => 'normal', 5 => 'low'][$priority];
            $pending = SendBaleNotification::dispatch((int) $delivery->id)->onQueue($queue);
            if ($notification->scheduled_at && strtotime($notification->scheduled_at) > time()) {
                $pending->delay(\Illuminate\Support\Carbon::parse($notification->scheduled_at));
            }
        }

        return (int) $delivery->id;
    }

    private function linkKey(string $token): string
    {
        return 'bale:link:'.hash('sha256', $token);
    }
}
