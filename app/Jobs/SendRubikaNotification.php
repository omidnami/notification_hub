<?php

namespace App\Jobs;

use App\Services\RubikaApiException;
use App\Services\RubikaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendRubikaNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(RubikaService $rubika): void
    {
        Cache::lock('rubika:delivery:'.$this->deliveryId, 40)->block(5, function () use ($rubika): void {
            $delivery = DB::table('notification_deliveries')->where('id', $this->deliveryId)->first();

            if (!$delivery || $delivery->channel !== 'rubika' || in_array($delivery->status, ['sent', 'failed'], true)) {
                return;
            }

            $notification = DB::table('notifications')
                ->where('id', $delivery->notification_id)->whereNull('deleted_at')->first();
            $connection = DB::table('connections')->where('id', $delivery->connection_id)->first();

            if (!$notification || !$connection || $connection->provider !== 'rubika'
                || $connection->status !== 'active' || (int) $notification->user_id !== (int) $connection->user_id) {
                $this->record('failed', 'Rubika notification or connection unavailable.');
                return;
            }

            if ($notification->scheduled_at && strtotime($notification->scheduled_at) > time()) {
                $this->release(max(1, strtotime($notification->scheduled_at) - time()));
                return;
            }

            $this->record('processing');
            $message = trim($notification->title."\n\n".($notification->body ?? ''));

            try {
                $result = $rubika->sendMessage((string) $connection->external_id, $message);
                DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
                    'status' => 'sent',
                    'attempts' => $this->attempts(),
                    'response' => json_encode($result, JSON_THROW_ON_ERROR),
                    'last_error' => null,
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (RubikaApiException $e) {
                if ($e->getCode() === 403) {
                    DB::table('connections')->where('id', $connection->id)
                        ->update(['status' => 'blocked', 'updated_at' => now()]);
                }

                if (!$e->retryable) {
                    $this->record('failed', 'Rubika refused delivery.');
                    return;
                }

                $this->record('retrying', 'Rubika temporarily unavailable.');
                if ($e->retryAfter > 0) {
                    $this->release($e->retryAfter);
                    return;
                }

                throw $e;
            } catch (Throwable $e) {
                $this->record('retrying', 'Temporary Rubika transport error.');
                throw $e;
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->record('failed', 'Rubika delivery exhausted retries.');
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
