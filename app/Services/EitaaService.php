<?php

namespace App\Services;

use App\Jobs\SendEitaaNotification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class EitaaService
{
    private const PAIRING_TTL_MINUTES = 10;
    private const PROVIDER = 'eitaa';
    private const USAGE_KEY = 'eitaa.sent_count';

    public function createPairingCode(User $user): string
    {
        if ((string) config('services.eitaa.token') === '') {
            throw new RuntimeException('Eitaa app token is not configured.');
        }

        $code = Str::upper(Str::random(16));
        Cache::put($this->pairingKey($code), $user->getKey(), now()->addMinutes(self::PAIRING_TTL_MINUTES));

        return $code;
    }

    public function connectionFor(User $user): ?object
    {
        return DB::table('connections')
            ->where('user_id', $user->getKey())
            ->where('provider', self::PROVIDER)
            ->first();
    }

    public function disconnect(User $user): void
    {
        DB::table('connections')
            ->where('user_id', $user->getKey())
            ->where('provider', self::PROVIDER)
            ->update(['status' => 'disabled', 'updated_at' => now()]);
    }

    public function completePairing(string $code, string $eitaaUserId): void
    {
        if (!preg_match('/^[a-zA-Z0-9]{16}$/', $code)
            || !ctype_digit($eitaaUserId) || $eitaaUserId === '0') {
            throw new InvalidArgumentException('Invalid Eitaa pairing request.');
        }

        $key = $this->pairingKey($code);
        Cache::lock('eitaa:pair:'.hash('sha256', $code), 15)->block(3, function () use ($key, $eitaaUserId): void {
            $userId = Cache::get($key);
            if (!is_numeric($userId) || (int) $userId <= 0) {
                throw new InvalidArgumentException('Expired Eitaa pairing code.');
            }

            DB::transaction(function () use ($userId, $eitaaUserId): void {
                if (!DB::table('users')->where('id', $userId)->lockForUpdate()->exists()) {
                    throw new InvalidArgumentException('Account no longer exists.');
                }

                if (DB::table('connections')->where('provider', self::PROVIDER)
                    ->where('external_id', $eitaaUserId)
                    ->where('user_id', '<>', $userId)->exists()) {
                    throw new InvalidArgumentException('Eitaa account is already connected.');
                }

                $existing = DB::table('connections')
                    ->where('user_id', $userId)
                    ->where('provider', self::PROVIDER)
                    ->lockForUpdate()->first();

                $now = now();
                if ($existing) {
                    DB::table('connections')->where('id', $existing->id)->update([
                        'external_id' => $eitaaUserId,
                        'status' => 'active',
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('connections')->insert([
                        'user_id' => $userId,
                        'provider' => self::PROVIDER,
                        'external_id' => $eitaaUserId,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

            // Consume only after successful database commit.
            Cache::forget($key);
        });
    }

    public function sendMessage(string $chatId, string $text): array
    {
        $token = (string) config('services.eitaa.token');
        if ($token === '') {
            throw new RuntimeException('Eitaa app token is not configured.');
        }
        if (!ctype_digit($chatId) || trim($text) === '') {
            throw new InvalidArgumentException('Eitaa chat ID and message text are required.');
        }

        // Lock serializes the local message budget check across workers.
        return Cache::lock('eitaa:budget:send', 35)->block(8, function () use ($token, $chatId, $text): array {
            $this->initializeCounter();
            $used = $this->sentCount();
            $limit = max(0, (int) config('services.eitaa.message_budget', 0));

            if ($limit > 0 && $used >= $limit) {
                throw new EitaaApiException('Local Eitaa message budget exhausted.');
            }

            $response = Http::acceptJson()->timeout(15)->post(
                'https://eitaayar.ir/api/app/sendMessage',
                ['token' => $token, 'chat_id' => $chatId, 'text' => $text],
            );

            $payload = $response->json();
            if (!$response->successful() || !is_array($payload)
                || ($payload['ok'] ?? null) !== true
                || ($payload['result'] ?? null) !== 'success') {
                $code = $response->status();
                $retryAfter = max(0, (int) $response->header('Retry-After', 0));

                throw new EitaaApiException(
                    'Eitaa API did not accept the message.',
                    $code,
                    $code === 429 || $code >= 500,
                    $retryAfter,
                );
            }

            DB::table('options')
                ->where('scope_type', 'global')->where('scope_id', 0)->where('key', self::USAGE_KEY)
                ->update(['value' => json_encode($used + 1, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $payload;
        });
    }

    public function sentCount(): int
    {
        $value = DB::table('options')
            ->where('scope_type', 'global')->where('scope_id', 0)->where('key', self::USAGE_KEY)
            ->value('value');

        return max(0, (int) json_decode((string) ($value ?? '0'), true));
    }

    public function queueDelivery(int $notificationId, int $connectionId): int
    {
        $notification = DB::table('notifications')
            ->where('id', $notificationId)->whereNull('deleted_at')->first();
        $connection = DB::table('connections')->where('id', $connectionId)->first();

        if (!$notification || !$connection || (int) $notification->user_id !== (int) $connection->user_id
            || $connection->provider !== self::PROVIDER || $connection->status !== 'active') {
            throw new InvalidArgumentException('Invalid notification or Eitaa connection.');
        }

        DB::table('notification_deliveries')->insertOrIgnore([
            'notification_id' => $notificationId,
            'connection_id' => $connectionId,
            'channel' => self::PROVIDER,
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
            $pending = SendEitaaNotification::dispatch((int) $delivery->id)->onQueue($queue);

            if ($notification->scheduled_at && Carbon::parse($notification->scheduled_at)->isFuture()) {
                $pending->delay(Carbon::parse($notification->scheduled_at));
            }
        }

        return (int) $delivery->id;
    }

    private function initializeCounter(): void
    {
        DB::table('options')->insertOrIgnore([
            'scope_type' => 'global',
            'scope_id' => 0,
            'key' => self::USAGE_KEY,
            'value' => json_encode(0, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pairingKey(string $code): string
    {
        return 'eitaa:pair:'.hash('sha256', $code);
    }
}
