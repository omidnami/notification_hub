<?php

namespace App\Jobs;

use App\Services\EitaaApiException;
use App\Services\EitaaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendEitaaNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(EitaaService $eitaa): void
    {
        Cache::lock('eitaa:delivery:'.$this->deliveryId, 40)->block(5, function () use ($eitaa): void {
            $delivery = DB::table('notification_deliveries')->where('id', $this->deliveryId)->first();
            if (!$delivery || $delivery->channel !== 'eitaa'
                || in_array($delivery->status, ['sent', 'failed'], true)) {
                return;
            }

            $notification = DB::table('notifications')
                ->where('id', $delivery->notification_id)->whereNull('deleted_at')->first();
            $connection = DB::table('connections')->where('id', $delivery->connection_id)->first();

            if (!$notification || !$connection || $connection->provider !== 'eitaa'
                || $connection->status !== 'active'
                || (int) $notification->user_id !== (int) $connection->user_id) {
                $this->record('failed', 'Eitaa notification or connection unavailable.');
                return;
            }

            if ($notification->scheduled_at && strtotime($notification->scheduled_at) > time()) {
                $this->release(max(1, strtotime($notification->scheduled_at) - time()));
                return;
            }

            $this->record('processing');

            try {
                $result = $eitaa->sendMessage(
                    (string) $connection->external_id,
                    trim($notification->title."\n\n".($notification->body ?? '')),
                );
                DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
                    'status' => 'sent',
                    'attempts' => $this->attempts(),
                    'response' => json_encode($result, JSON_THROW_ON_ERROR),
                    'last_error' => null,
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (EitaaApiException $e) {
                if (!$e->retryable) {
                    $this->record('failed', $e->getMessage());
                    return;
                }

                $this->record('retrying', 'Eitaa API temporarily unavailable.');
                if ($e->retryAfter > 0) {
                    $this->release($e->retryAfter);
                    return;
                }

                throw $e;
            } catch (Throwable $e) {
                $this->record('retrying', 'Temporary Eitaa transport error.');
                throw $e;
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        $this->record('failed', 'Eitaa delivery exhausted retries.');
    }

    private function record(string $status, ?string $error = null): void
    {
        DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
            'status' => $status,
            'attempts' => $this->attempts(),
            'last_error' => $error,
            'updated_at' => now(),
        ]);
    }
}
