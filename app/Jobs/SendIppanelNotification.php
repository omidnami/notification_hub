<?php

namespace App\Jobs;

use App\Services\Ippanel\IppanelAdapter;
use App\Services\Ippanel\IppanelException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendIppanelNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 35;
    public array $backoff = [45, 120, 300];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(IppanelAdapter $adapter): void
    {
        Cache::lock('ippanel:delivery:'.$this->deliveryId, 40)->block(5, function () use ($adapter): void {
            $row = DB::table('notification_deliveries as d')
                ->join('notifications as n', 'n.id', '=', 'd.notification_id')
                ->join('notification_channel_payloads as p', function ($join): void {
                    $join->on('p.notification_id', '=', 'n.id')->on('p.channel', '=', 'd.channel');
                })
                ->where('d.id', $this->deliveryId)
                ->whereIn('d.channel', ['sms', 'voice_otp'])
                ->select('d.status', 'd.channel', 'n.deleted_at', 'p.payload')
                ->first();

            if (!$row || in_array($row->status, ['sent', 'failed', 'needs_review'], true)) {
                return;
            }

            if ($row->deleted_at !== null) {
                $this->record('failed', 'Notification was deleted before sending.');
                return;
            }

            $this->record('processing');

            try {
                $payload = json_decode(Crypt::decryptString($row->payload), true, 10, JSON_THROW_ON_ERROR);
                $data = $adapter->send($row->channel, $payload);

                DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
                    'status' => 'sent',
                    'attempts' => $this->attempts(),
                    'response' => json_encode(['outbox_ids' => $data['message_outbox_ids'] ?? []], JSON_THROW_ON_ERROR),
                    'last_error' => null,
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (IppanelException $e) {
                if ($e->indeterminate) {
                    $this->record('needs_review', 'Provider result uncertain; do not blindly retry OTP.');
                    return;
                }
                if (!$e->retryable || $this->attempts() >= $this->tries) {
                    $this->record('failed', $e->getMessage());
                    return;
                }
                $this->record('retrying', 'IPPanel rate limit.');
                // IPPanel may return 429 for identical requests within 40 seconds.
                $this->release(max(45, $e->retryAfter));
            } catch (Throwable) {
                $this->record('needs_review', 'Unexpected IPPanel send result requires review.');
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->record('failed', 'IPPanel job failed after retries.');
    }

    private function record(string $status, ?string $message): void
    {
        DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
            'status' => $status,
            'attempts' => $this->attempts(),
            'last_error' => $message,
            'updated_at' => now(),
        ]);
    }
}
