<?php

namespace App\Services\Notifications;

use App\Jobs\SendIppanelNotification;
use App\Services\BaleService;
use App\Services\EitaaService;
use App\Services\Push\PushDispatchService;
use App\Services\RubikaService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NotificationCore
{
    public function __construct(
        private readonly NotificationEvents $events,
        private readonly BaleService $bale,
        private readonly RubikaService $rubika,
        private readonly EitaaService $eitaa,
        private readonly PushDispatchService $push,
    ) {}

    public function create(object $project, array $input, string $key): array
    {
        $channels = array_values(array_unique($input['channels'] ?? ['in_app']));
        sort($channels, SORT_STRING);

        $requestHash = hash('sha256', json_encode([
            'title' => $input['title'],
            'body' => $input['body'] ?? null,
            'priority' => $input['priority'] ?? 4,
            'scheduled_at' => $input['scheduled_at'] ?? null,
            'channels' => $channels,
            'sms' => $input['sms'] ?? null,
            'voice_otp' => $input['voice_otp'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $details = [];
        if (in_array('sms', $channels, true)) {
            $details['sms'] = $this->smsPayload($input['sms'] ?? null);
        }
        if (in_array('voice_otp', $channels, true)) {
            $details['voice_otp'] = $this->voicePayload($input['voice_otp'] ?? null);
        }

        return DB::transaction(function () use ($project, $input, $key, $requestHash, $channels, $details): array {
            DB::table('projects')->where('id', $project->id)->lockForUpdate()->first(['id']);

            $existing = DB::table('notifications')->where('project_id', $project->id)
                ->where('idempotency_key', $key)->first();

            if ($existing) {
                $saved = DB::table('options')->where('scope_type', 'notification')
                    ->where('scope_id', $existing->id)->where('key', 'request_hash')->value('value');
                $same = is_string($saved) && json_decode($saved, true) === $requestHash;
                return [
                    'id' => (int) $existing->id,
                    'duplicate' => true,
                    'conflict' => !$same,
                ];
            }

            foreach ($details as $payload) {
                $this->checkSmsQuota($project, $payload['to']);
            }

            $id = DB::table('notifications')->insertGetId([
                'user_id' => $project->user_id,
                'project_id' => $project->id,
                'title' => $input['title'],
                'body' => $input['body'] ?? null,
                'priority' => $input['priority'] ?? 4,
                'scheduled_at' => $input['scheduled_at'] ?? null,
                'status' => 'pending',
                'idempotency_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('options')->insert([
                'scope_type' => 'notification',
                'scope_id' => $id,
                'key' => 'request_hash',
                'value' => json_encode($requestHash, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($details as $channel => $payload) {
                DB::table('notification_channel_payloads')->insert([
                    'notification_id' => $id, 'channel' => $channel,
                    'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                    'recipient_hash' => hash_hmac('sha256', $payload['to'], (string) config('app.key')),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->events->publish((int) $project->id, (int) $id, 'notification.created', [
                'id' => $id, 'title' => $input['title'], 'priority' => $input['priority'] ?? 4,
            ]);

            DB::afterCommit(function () use ($id, $channels): void {
                $this->fanOut($id, $channels);
            });

            return ['id' => $id, 'duplicate' => false, 'conflict' => false];
        });
    }

    private function smsPayload(mixed $input): array
    {
        if (!is_array($input) || !preg_match('/^\+[1-9]\d{7,14}$/', (string) ($input['to'] ?? ''))
            || ($input['consent'] ?? false) !== true) {
            throw new InvalidArgumentException('SMS requires E.164 recipient and confirmed consent.');
        }

        $type = $input['type'] ?? 'text';
        if ($type === 'pattern') {
            if (!is_string($input['code'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{2,100}$/', $input['code'])
                || !is_array($input['params'] ?? null) || count($input['params']) > 10) {
                throw new InvalidArgumentException('Invalid IPPanel pattern.');
            }
            foreach ($input['params'] as $name => $value) {
                if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,50}$/', $name)
                    || !is_string($value) || mb_strlen($value) > 200) {
                    throw new InvalidArgumentException('Invalid pattern parameters.');
                }
            }
            return [
                'type' => 'pattern', 'to' => $input['to'],
                'code' => $input['code'], 'params' => $input['params'],
            ];
        }

        if ($type !== 'text' || !is_string($input['message'] ?? null)
            || trim($input['message']) === '' || mb_strlen($input['message']) > 1000) {
            throw new InvalidArgumentException('Invalid SMS text.');
        }

        return ['type' => 'text', 'to' => $input['to'], 'message' => $input['message']];
    }

    private function voicePayload(mixed $input): array
    {
        if (!is_array($input) || !preg_match('/^\+[1-9]\d{7,14}$/', (string) ($input['to'] ?? ''))
            || !preg_match('/^\d{4,8}$/', (string) ($input['code'] ?? ''))
            || ($input['consent'] ?? false) !== true) {
            throw new InvalidArgumentException('Voice OTP requires a valid number, 4-8 digits and consent.');
        }

        return ['to' => $input['to'], 'code' => $input['code']];
    }

    private function checkSmsQuota(object $project, string $phone): void
    {
        $base = DB::table('notification_channel_payloads as p')
            ->join('notifications as n', 'n.id', '=', 'p.notification_id')
            ->whereIn('p.channel', ['sms', 'voice_otp'])
            ->where('n.created_at', '>=', now()->subDay());

        $projectCount = (clone $base)->where('n.project_id', $project->id)->count();
        $userCount = (clone $base)->where('n.user_id', $project->user_id)->count();
        $recipientHash = hash_hmac('sha256', $phone, (string) config('app.key'));
        $recent = (clone $base)
            ->where('n.user_id', $project->user_id)
            ->where('p.recipient_hash', $recipientHash)
            ->where('n.created_at', '>=', now()->subHour())->count();

        if ($projectCount >= (int) config('services.ippanel.project_daily_limit', 100)
            || $userCount >= (int) config('services.ippanel.user_daily_limit', 300)
            || $recent >= (int) config('services.ippanel.recipient_hourly_limit', 5)) {
            throw new InvalidArgumentException('SMS/voice quota reached.');
        }
    }

    private function fanOut(int $notificationId, array $channels): void
    {
        $notification = DB::table('notifications')->where('id', $notificationId)
            ->whereNull('deleted_at')->first();
        if (!$notification) {
            return;
        }

        $providers = [
            'bale' => $this->bale,
            'rubika' => $this->rubika,
            'eitaa' => $this->eitaa,
        ];

        foreach ($channels as $channel) {
            if ($channel === 'in_app') {
                continue;
            }

            try {
                if ($channel === 'webpush') {
                    $queued = $this->push->queueNotification($notificationId);
                    if (!$queued) {
                        $this->recordUnavailable($notificationId, $channel, 'No active Web Push devices.');
                    }
                    continue;
                }

                if (in_array($channel, ['sms', 'voice_otp'], true)) {
                    $deliveryId = DB::table('notification_deliveries')->insertGetId([
                        'notification_id' => $notificationId,
                        'channel' => $channel,
                        'status' => 'pending', 'attempts' => 0,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $queue = $this->queue((int) $notification->priority);
                    $job = SendIppanelNotification::dispatch((int) $deliveryId)->onQueue($queue);
                    if ($notification->scheduled_at) {
                        $job->delay(\Illuminate\Support\Carbon::parse($notification->scheduled_at));
                    }
                    continue;
                }

                if (!isset($providers[$channel])) {
                    continue;
                }

                $connection = DB::table('connections')->where('user_id', $notification->user_id)
                    ->where('provider', $channel)->where('status', 'active')->first(['id']);

                if (!$connection) {
                    $this->recordUnavailable($notificationId, $channel, 'Channel is not linked to this user.');
                    continue;
                }

                $providers[$channel]->queueDelivery($notificationId, (int) $connection->id);
            } catch (\Throwable) {
                \Illuminate\Support\Facades\Log::error('Notification channel dispatch failed.', [
                    'notification_id' => $notificationId, 'channel' => $channel,
                ]);
                if (!DB::table('notification_deliveries')
                    ->where('notification_id', $notificationId)->where('channel', $channel)->exists()) {
                    $this->recordUnavailable($notificationId, $channel, 'Channel dispatch failed.');
                }
            }
        }
    }

    private function recordUnavailable(int $id, string $channel, string $reason): void
    {
        DB::table('notification_deliveries')->insert([
            'notification_id' => $id,
            'channel' => $channel,
            'status' => 'failed',
            'last_error' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function queue(int $priority): string
    {
        return [1 => 'critical', 2 => 'urgent', 3 => 'high', 4 => 'normal', 5 => 'low'][max(1, min(5, $priority))];
    }
}
