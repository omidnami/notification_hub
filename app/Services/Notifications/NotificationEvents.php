<?php

namespace App\Services\Notifications;

use App\Jobs\SendOutgoingWebhook;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationEvents
{
    public function publish(int $projectId, ?int $notificationId, string $event, array $data): void
    {
        $hooks = DB::table('webhooks')->where('project_id', $projectId)
            ->where('is_active', true)->get(['id', 'events']);

        $eventId = (string) Str::uuid();
        $body = [
            'id' => $eventId,
            'type' => $event,
            'occurred_at' => now()->toIso8601String(),
            'project_id' => $projectId,
            'notification_id' => $notificationId,
            'data' => $data,
        ];

        foreach ($hooks as $hook) {
            $events = json_decode((string) $hook->events, true);
            if (!is_array($events) || !in_array($event, $events, true)) {
                continue;
            }

            $deliveryId = DB::table('webhook_deliveries')->insertGetId([
                'webhook_id' => $hook->id,
                'notification_id' => $notificationId,
                'event' => $event,
                'event_key' => hash('sha256', $eventId),
                'payload' => Crypt::encryptString(json_encode($body, JSON_THROW_ON_ERROR)),
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            SendOutgoingWebhook::dispatch((int) $deliveryId)->onQueue('normal')->afterCommit();
        }
    }
}
