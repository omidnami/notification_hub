<?php

namespace App\Jobs;

use App\Services\Push\PushSendException;
use App\Services\Push\WebPushAdapter;
use App\Services\Push\PushSubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendWebPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 40;
    public array $backoff = [15, 60, 300];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(WebPushAdapter $sender, PushSubscriptionService $subscriptions): void
    {
        Cache::lock('webpush:delivery:'.$this->deliveryId, 45)->block(5, function () use ($sender, $subscriptions): void {
            $row = DB::table('notification_deliveries as d')
                ->join('notifications as n', 'n.id', '=', 'd.notification_id')
                ->leftJoin('push_subscriptions as s', 's.id', '=', 'd.push_subscription_id')
                ->leftJoin('web_push_messages as p', 'p.notification_id', '=', 'n.id')
                ->where('d.id', $this->deliveryId)->where('d.channel', 'webpush')
                ->select(
                    'd.status', 'n.title', 'n.body', 'n.deleted_at', 'n.user_id',
                    's.id as device_id', 's.user_id as device_user_id', 's.status as device_status',
                    's.subscription', 'p.icon', 'p.target_url',
                )->first();

            if (!$row || in_array($row->status, ['sent', 'failed', 'needs_review'], true)) {
                return;
            }

            if ($row->deleted_at || !$row->device_id || $row->device_status !== 'active'
                || (int) $row->user_id !== (int) $row->device_user_id) {
                $this->record('failed', 'Push device or notification unavailable.');
                return;
            }

            $this->record('processing');
            try {
                $payload = [
                    'title' => mb_substr($row->title, 0, 120),
                    'body' => mb_substr((string) $row->body, 0, 500),
                    'icon' => $row->icon ?: '/push-icon.svg',
                    'url' => $row->target_url ?: '/',
                    'tag' => 'notification-'.$this->deliveryId,
                ];
                $result = $sender->send($subscriptions->data($row), $payload);
                DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
                    'status' => 'sent', // Accepted by browser push service; not device delivery.
                    'attempts' => $this->attempts(),
                    'last_error' => null,
                    'response' => json_encode($result, JSON_THROW_ON_ERROR),
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('push_subscriptions')->where('id', $row->device_id)->update([
                    'last_success_at' => now(), 'last_error' => null, 'updated_at' => now(),
                ]);
            } catch (PushSendException $exception) {
                if ($exception->expired) {
                    DB::table('push_subscriptions')->where('id', $row->device_id)
                        ->update(['status' => 'expired', 'last_error' => 'Subscription expired.', 'updated_at' => now()]);
                    $this->record('failed', 'Push subscription expired.');
                    return;
                }
                if ($exception->indeterminate) {
                    $this->record('needs_review', 'Push outcome uncertain; not retrying automatically.');
                    return;
                }
                if (!$exception->retryable || $this->attempts() >= $this->tries) {
                    $this->record('failed', $exception->getMessage());
                    return;
                }

                $this->record('retrying', 'Push service temporarily unavailable.');
                $this->release(max(15, $exception->retryAfter));
            } catch (Throwable) {
                $this->record('needs_review', 'Unexpected push outcome; manual review required.');
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->record('failed', 'Push job exhausted retries.');
    }

    private function record(string $status, ?string $error = null): void
    {
        DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
            'status' => $status, 'attempts' => $this->attempts(),
            'last_error' => $error, 'updated_at' => now(),
        ]);
    }
}
