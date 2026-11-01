<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramException;
use App\Services\Telegram\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendTelegramNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;
    public int $timeout = 35;
    public array $backoff = [10, 60, 180, 300];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(TelegramService $telegram): void
    {
        Cache::lock('telegram:delivery:'.$this->deliveryId, 42)->block(5, function () use ($telegram): void {
            $delivery = DB::table('notification_deliveries')
                ->where('id', $this->deliveryId)->first();
            if (!$delivery || $delivery->channel !== 'telegram'
                || in_array($delivery->status, ['sent', 'failed', 'needs_review'], true)) {
                return;
            }

            $notification = DB::table('notifications')->where('id', $delivery->notification_id)
                ->whereNull('deleted_at')->first();
            $connection = DB::table('connections')->where('id', $delivery->connection_id)->first();

            if (!$notification || !$connection || $connection->provider !== 'telegram'
                || $connection->status !== 'active'
                || (int) $notification->user_id !== (int) $connection->user_id
                || !preg_match('/^[1-9][0-9]{0,18}$/', (string) $connection->external_id)) {
                $this->record('failed', 'Telegram connection unavailable or disconnected.');
                return;
            }

            if ($notification->scheduled_at && strtotime($notification->scheduled_at) > time()) {
                $this->release(max(1, strtotime($notification->scheduled_at) - time()));
                return;
            }

            $this->record('processing');

            try {
                $text = trim($notification->title."\n\n".($notification->body ?? ''));
                if (mb_strlen($text) > 4096) {
                    // Bot API hard limit; truncate safely while preserving readable text.
                    $text = mb_substr($text, 0, 4095).'…';
                }

                $result = $telegram->sendMessage((string) $connection->external_id, $text);

                DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
                    'status' => 'sent', // Bot API accepted, not confirmed user read.
                    'attempts' => $this->attempts(),
                    'response' => json_encode($result, JSON_THROW_ON_ERROR),
                    'last_error' => null,
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (TelegramException $e) {
                if ($e->httpStatus === 403) {
                    DB::table('connections')->where('id', $connection->id)
                        ->where('external_id', $connection->external_id)
                        ->update(['status' => 'blocked', 'updated_at' => now()]);
                    $this->record('failed', 'Telegram bot was blocked or forbidden by recipient.');
                    return;
                }

                if ($e->indeterminate) {
                    // Telegram sendMessage has no idempotency key; a blind retry may double-send.
                    $this->record('needs_review', 'Telegram acceptance uncertain; automatic retry disabled.');
                    return;
                }

                if (!$e->retryable || $this->attempts() >= $this->tries) {
                    $this->record('failed', 'Telegram rejected delivery.');
                    return;
                }

                $this->record('retrying', 'Telegram flood control; retry scheduled.');
                $this->release(max(2, $e->retryAfter));
            } catch (Throwable) {
                $this->record('needs_review', 'Unexpected Telegram send outcome; review required.');
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        // Do not overwrite a provider-confirmed success if the worker fails later.
        DB::table('notification_deliveries')->where('id', $this->deliveryId)
            ->whereNotIn('status', ['sent', 'needs_review'])
            ->update([
                'status' => 'failed',
                'last_error' => 'Telegram job exhausted retries.',
                'attempts' => $this->attempts(),
                'updated_at' => now(),
            ]);
    }

    private function record(string $status, ?string $error = null): void
    {
        DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
            'status' => $status,
            'last_error' => $error,
            'attempts' => $this->attempts(),
            'updated_at' => now(),
        ]);
    }
}
