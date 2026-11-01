<?php

namespace App\Services\Telegram;

use App\Jobs\SendTelegramNotification;
use App\Jobs\SendTelegramWelcome;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TelegramService
{
    public function __construct(private readonly TelegramInternalClient $internal) {}

    public function connectionFor(User $user): ?object
    {
        return DB::table('connections')->where('user_id', $user->getKey())
            ->where('provider', 'telegram')->first();
    }

    public function connectionUrl(User $user): string
    {
        $username = ltrim((string) config('services.telegram.username'), '@');

        if (config('services.telegram.role') !== 'core'
            || !preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)
            || !str_ends_with(strtolower($username), 'bot')) {
            throw new RuntimeException('Telegram central bot username is not configured.');
        }

        $token = Str::random(40);
        Cache::put($this->linkKey($token), (int) $user->getKey(), now()->addMinutes(10));
        Cache::put($this->activeKey((int) $user->getKey()), hash('sha256', $token), now()->addMinutes(10));

        return 'https://t.me/'.$username.'?start='.$token;
    }

    public function disconnect(User $user): void
    {
        DB::transaction(function () use ($user): void {
            DB::table('users')->where('id', $user->getKey())->lockForUpdate()->first(['id']);

            $connections = DB::table('connections')->where('user_id', $user->getKey())
                ->where('provider', 'telegram')->get(['id']);

            foreach ($connections as $connection) {
                // Prevent old pending deliveries from going to a newly linked chat.
                DB::table('notification_deliveries')
                    ->where('connection_id', $connection->id)
                    ->where('channel', 'telegram')
                    ->whereIn('status', ['pending', 'retrying'])
                    ->update([
                        'status' => 'failed',
                        'last_error' => 'Telegram account disconnected before delivery.',
                        'updated_at' => now(),
                    ]);
            }

            DB::table('connections')->where('user_id', $user->getKey())
                ->where('provider', 'telegram')
                ->update(['external_id' => null, 'status' => 'disabled', 'updated_at' => now()]);
        });
        Cache::forget($this->activeKey((int) $user->getKey()));
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['my_chat_member']) && is_array($update['my_chat_member'])) {
            $this->handleMembership($update['my_chat_member']);
        }

        $message = $update['message'] ?? null;
        if (!is_array($message) || ($message['chat']['type'] ?? null) !== 'private') {
            return;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $senderId = (string) ($message['from']['id'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,18}$/', $chatId)
            || $senderId !== $chatId || ($message['from']['is_bot'] ?? false) === true) {
            return;
        }

        if (!preg_match('/^\/start ([A-Za-z0-9]{40})$/', trim((string) ($message['text'] ?? '')), $matches)) {
            return;
        }

        $token = $matches[1];
        $key = $this->linkKey($token);

        $linked = Cache::lock('telegram:pair:'.hash('sha256', $token), 15)->block(3,
            function () use ($key, $chatId, $token): bool {
                $userId = Cache::get($key);
                if (!is_numeric($userId) || (int) $userId < 1
                    || Cache::get($this->activeKey((int) $userId)) !== hash('sha256', $token)) {
                    return false;
                }

                $success = DB::transaction(function () use ($userId, $chatId): bool {
                    if (!DB::table('users')->where('id', $userId)->lockForUpdate()->exists()) {
                        return false;
                    }

                    if (DB::table('connections')->where('provider', 'telegram')
                        ->where('external_id', $chatId)->where('user_id', '<>', $userId)->exists()) {
                        return false;
                    }

                    $row = DB::table('connections')->where('provider', 'telegram')
                        ->where('user_id', $userId)->lockForUpdate()->first();

                    $values = [
                        'external_id' => $chatId,
                        'status' => 'active',
                        'updated_at' => now(),
                    ];

                    if ($row) {
                        DB::table('connections')->where('id', $row->id)->update($values);
                    } else {
                        DB::table('connections')->insert([
                            'user_id' => $userId,
                            'provider' => 'telegram',
                            'external_id' => $chatId,
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    return true;
                });

                if ($success) {
                    Cache::forget($key);
                    Cache::forget($this->activeKey((int) $userId));
                }

                return $success;
            },
        );

        if ($linked) {
            // Never block the inbound webhook while waiting for the Germany worker.
            SendTelegramWelcome::dispatch($chatId)->onQueue('normal')->afterCommit();
        }
    }

    private function handleMembership(array $change): void
    {
        if (($change['chat']['type'] ?? null) !== 'private'
            || ($change['new_chat_member']['status'] ?? null) !== 'kicked') {
            return;
        }

        $chatId = (string) ($change['chat']['id'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,18}$/', $chatId)) {
            return;
        }

        DB::table('connections')->where('provider', 'telegram')
            ->where('external_id', $chatId)
            ->update(['status' => 'blocked', 'updated_at' => now()]);
    }

    public function sendMessage(string $chatId, string $text): array
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/', $chatId)
            || trim($text) === '' || mb_strlen($text) > 4096) {
            throw new InvalidArgumentException('Invalid Telegram private-chat message.');
        }

        try {
            $response = $this->internal->post('gateway', [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (Throwable) {
            throw new TelegramException('Telegram gateway outcome uncertain.', 0, false, 0, true);
        }

        $result = $response->json();
        if ($response->successful() && is_array($result)
            && ($result['ok'] ?? false) === true
            && isset($result['result']['message_id'])) {
            return ['message_id' => $result['result']['message_id']];
        }

        $code = (int) (is_array($result)
            ? ($result['error_code'] ?? $response->status()) : $response->status());

        throw new TelegramException(
            'Telegram gateway did not confirm acceptance.',
            $code,
            $code === 429,
            $code === 429 ? max(1, min(3600, (int) ($result['retry_after']
                ?? $response->header('Retry-After', 1)))) : 0,
            ($result['indeterminate'] ?? false) === true || $response->status() >= 500
                || ($response->successful() && !isset($result['result']['message_id'])),
        );
    }

    public function queueDelivery(int $notificationId, int $connectionId): int
    {
        $notification = DB::table('notifications')->where('id', $notificationId)
            ->whereNull('deleted_at')->first();
        $connection = DB::table('connections')->where('id', $connectionId)->first();

        if (!$notification || !$connection
            || (int) $notification->user_id !== (int) $connection->user_id
            || $connection->provider !== 'telegram' || $connection->status !== 'active') {
            throw new InvalidArgumentException('Invalid Telegram notification connection.');
        }

        // Lock the Hub owner while enforcing per-user/project daily limits.
        // Existing idempotent deliveries do not consume quota twice.
        $delivery = DB::transaction(function () use ($notification, $notificationId, $connectionId): ?object {
            DB::table('users')->where('id', $notification->user_id)
                ->lockForUpdate()->first(['id']);

            $query = DB::table('notification_deliveries as d')
                ->join('notifications as n', 'n.id', '=', 'd.notification_id')
                ->where('d.channel', 'telegram')
                ->where('d.created_at', '>=', now()->subDay());

            $already = DB::table('notification_deliveries')
                ->where('notification_id', $notificationId)
                ->where('connection_id', $connectionId)->first();

            if (!$already) {
                $userCount = (clone $query)->where('n.user_id', $notification->user_id)->count();
                $projectCount = $notification->project_id === null ? 0
                    : (clone $query)->where('n.project_id', $notification->project_id)->count();

                if ($userCount >= (int) config('services.telegram.user_daily_limit', 300)
                    || $projectCount >= (int) config('services.telegram.project_daily_limit', 150)) {
                    throw new InvalidArgumentException('Telegram daily quota exceeded.');
                }

                DB::table('notification_deliveries')->insertOrIgnore([
                    'notification_id' => $notificationId,
                    'connection_id' => $connectionId,
                    'channel' => 'telegram',
                    'status' => 'pending',
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return DB::table('notification_deliveries')
                ->where('notification_id', $notificationId)
                ->where('connection_id', $connectionId)->first();
        });

        if (!$delivery) {
            throw new RuntimeException('Telegram delivery could not be created.');
        }

        if ($delivery->status === 'pending') {
            $priority = max(1, min(5, (int) $notification->priority));
            $queue = [1 => 'critical', 2 => 'urgent', 3 => 'high', 4 => 'normal', 5 => 'low'][$priority];
            $pending = SendTelegramNotification::dispatch((int) $delivery->id)
                ->onQueue($queue)->afterCommit();

            if ($notification->scheduled_at
                && Carbon::parse($notification->scheduled_at)->isFuture()) {
                $pending->delay(Carbon::parse($notification->scheduled_at));
            }
        }

        return (int) $delivery->id;
    }

    private function activeKey(int $userId): string
    {
        return 'telegram:current:'.$userId;
    }

    private function linkKey(string $token): string
    {
        return 'telegram:link:'.hash('sha256', $token);
    }
}
