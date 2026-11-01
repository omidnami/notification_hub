<?php

namespace App\Jobs;

use App\Services\Webhooks\UrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class SendOutgoingWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;
    public int $timeout = 25;
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(UrlGuard $guard): void
    {
        Cache::lock('webhook:delivery:'.$this->deliveryId, 30)->block(5, function () use ($guard): void {
            $row = DB::table('webhook_deliveries as d')
                ->leftJoin('webhooks as w', 'w.id', '=', 'd.webhook_id')
                ->where('d.id', $this->deliveryId)
                ->select('d.status', 'd.event_key', 'd.payload', 'w.url', 'w.secret', 'w.is_active')
                ->first();

            if (!$row || in_array($row->status, ['sent', 'failed'], true)) {
                return;
            }

            if (!$row->url || !$row->is_active || !$row->payload || !$row->secret) {
                $this->record('failed', 'Webhook is disabled or missing.');
                return;
            }

            try {
                $guard->check($row->url);
            } catch (\InvalidArgumentException) {
                $this->record('failed', 'Webhook destination is not permitted.');
                return;
            }

            $this->record('processing');

            $payload = Crypt::decryptString($row->payload);
            $secret = Crypt::decryptString($row->secret);
            $timestamp = (string) time();
            $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'X-NotificationHub-Event' => $row->event_key,
                    'X-NotificationHub-Time' => $timestamp,
                    'X-NotificationHub-Signature' => 'sha256='.$signature,
                ])->withoutRedirecting()->connectTimeout(4)->timeout(15)
                    ->withBody($payload, 'application/json')->post($row->url);
            } catch (Throwable) {
                // Receiver can deduplicate X-NotificationHub-Event across retries.
                $this->retryOrFail('Webhook transport error.', 60);
                return;
            }

            if ($response->successful()) {
                DB::table('webhook_deliveries')->where('id', $this->deliveryId)->update([
                    'status' => 'sent',
                    'attempts' => $this->attempts(),
                    'http_status' => $response->status(),
                    'last_error' => null,
                    'sent_at' => now(),
                    'next_attempt_at' => null,
                    'updated_at' => now(),
                ]);
                return;
            }

            DB::table('webhook_deliveries')->where('id', $this->deliveryId)->update([
                'http_status' => $response->status(),
                'updated_at' => now(),
            ]);

            if ($response->status() === 429 || $response->status() >= 500) {
                $this->retryOrFail(
                    'Webhook temporary HTTP '.$response->status(),
                    max(30, min(3600, (int) $response->header('Retry-After', 0))),
                );
                return;
            }

            $this->record('failed', 'Webhook returned HTTP '.$response->status());
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->record('failed', 'Webhook retries exhausted.');
    }

    private function retryOrFail(string $error, int $seconds): void
    {
        if ($this->attempts() >= $this->tries) {
            $this->record('failed', $error);
            return;
        }

        DB::table('webhook_deliveries')->where('id', $this->deliveryId)->update([
            'status' => 'retrying',
            'attempts' => $this->attempts(),
            'last_error' => $error,
            'next_attempt_at' => now()->addSeconds($seconds),
            'updated_at' => now(),
        ]);
        $this->release($seconds);
    }

    private function record(string $status, string $error): void
    {
        DB::table('webhook_deliveries')->where('id', $this->deliveryId)->update([
            'status' => $status,
            'attempts' => $this->attempts(),
            'last_error' => $error,
            'next_attempt_at' => null,
            'updated_at' => now(),
        ]);
    }
}
