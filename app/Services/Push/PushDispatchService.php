<?php

namespace App\Services\Push;

use App\Jobs\SendWebPushNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PushDispatchService
{
    public function queueNotification(int $notificationId, ?array $deviceIds = null): array
    {
        $notification = DB::table('notifications')->where('id', $notificationId)
            ->whereNull('deleted_at')->first();

        if (!$notification) {
            throw new InvalidArgumentException('Notification not found.');
        }

        $devices = DB::table('push_subscriptions')
            ->where('user_id', $notification->user_id)
            ->where('status', 'active')
            ->when($deviceIds !== null, function ($query) use ($deviceIds): void {
                $query->whereIn('id', $deviceIds);
            })->get(['id']);

        $queued = [];
        foreach ($devices as $device) {
            DB::table('notification_deliveries')->insertOrIgnore([
                'notification_id' => $notificationId,
                'push_subscription_id' => $device->id,
                'connection_id' => null,
                'channel' => 'webpush',
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $delivery = DB::table('notification_deliveries')
                ->where('notification_id', $notificationId)
                ->where('push_subscription_id', $device->id)->first();

            if ($delivery && $delivery->status === 'pending') {
                $priority = max(1, min(5, (int) $notification->priority));
                $queue = [1 => 'critical', 2 => 'urgent', 3 => 'high', 4 => 'normal', 5 => 'low'][$priority];
                $scheduled = $notification->scheduled_at
                    ? Carbon::parse($notification->scheduled_at) : null;

                // Queued dispatch respects transaction commit; outside transactions it is immediate.
                $pending = SendWebPushNotification::dispatch((int) $delivery->id)
                    ->onQueue($queue)->afterCommit();
                if ($scheduled?->isFuture()) {
                    $pending->delay($scheduled);
                }
                unset($pending);

                $queued[] = (int) $delivery->id;
            }
        }

        return $queued;
    }
}
