<?php

namespace App\Jobs;

use App\Services\Email\EmailAccountService;
use App\Services\Email\GatewayClient;
use App\Services\Email\MailGatewayException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendEmailNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 40;
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(GatewayClient $gateway, EmailAccountService $accounts): void
    {
        Cache::lock('email:delivery:'.$this->deliveryId, 50)->block(5, function () use ($gateway, $accounts): void {
            $row = DB::table('notification_deliveries as d')
                ->join('notifications as n', 'n.id', '=', 'd.notification_id')
                ->join('email_messages as m', 'm.notification_id', '=', 'n.id')
                ->leftJoin('connections as c', 'c.id', '=', 'm.connection_id')
                ->where('d.id', $this->deliveryId)->where('d.channel', 'email')
                ->select(
                    'd.status', 'd.id as delivery_id', 'd.connection_id',
                    'n.user_id', 'n.project_id', 'n.deleted_at',
                    'c.provider', 'c.status as account_status', 'c.user_id as account_user_id',
                    'c.credentials', 'c.external_id as from_email',
                    'm.payload',
                )->first();

            if (!$row || in_array($row->status, ['sent', 'failed', 'needs_review'], true)) {
                return;
            }

            if ($row->deleted_at !== null || !$row->credentials || $row->account_status !== 'active'
                || (int) $row->user_id !== (int) $row->account_user_id
                || !in_array($row->provider, ['gmail', 'smtp'], true)
                || !DB::table('project_email_accounts')->where('project_id', $row->project_id)
                    ->where('connection_id', $row->connection_id)->exists()) {
                $this->record('failed', 'Verified sender is unavailable or project access was revoked.');
                return;
            }

            $quota = DB::transaction(function () use ($row): bool {
                DB::table('users')->where('id', $row->user_id)->lockForUpdate()->first(['id']);

                $base = DB::table('notification_deliveries as d')
                    ->join('notifications as n', 'n.id', '=', 'd.notification_id')
                    ->where('d.channel', 'email')
                    ->where('d.created_at', '>=', now()->startOfDay())
                    ->whereIn('d.status', ['processing', 'sent', 'needs_review']);

                $userCount = (clone $base)->where('n.user_id', $row->user_id)->count();
                $projectCount = (clone $base)->where('n.project_id', $row->project_id)->count();

                if ($userCount >= (int) config('services.email_gateway.user_daily_limit', 50)
                    || $projectCount >= (int) config('services.email_gateway.project_daily_limit', 30)) {
                    return false;
                }

                DB::table('notification_deliveries')->where('id', $this->deliveryId)
                    ->update(['status' => 'processing', 'attempts' => $this->attempts(), 'updated_at' => now()]);

                return true;
            });

            if (!$quota) {
                $this->record('failed', 'Daily email quota exceeded.');
                return;
            }

            try {
                $message = json_decode(Crypt::decryptString($row->payload), true, 16, JSON_THROW_ON_ERROR);
                $account = $accounts->credentials($row);

                // The sender is always the email address verified for this account.
                $message['from'] = $row->from_email;
                $result = $gateway->call(
                    $row->provider.'.send',
                    [
                        'delivery_key' => hash('sha256', (string) config('app.key').'|'.$this->deliveryId),
                        'account' => $account,
                        'message' => $message,
                    ],
                );

                DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
                    'status' => 'sent', // Accepted by provider; NOT final inbox delivery.
                    'attempts' => $this->attempts(),
                    'response' => json_encode($result, JSON_THROW_ON_ERROR),
                    'last_error' => null, 'sent_at' => now(), 'updated_at' => now(),
                ]);
            } catch (MailGatewayException $e) {
                if ($e->indeterminate) {
                    $this->record('needs_review', 'Provider delivery result is uncertain; automatic retry disabled.');
                    return;
                }
                if (!$e->retryable || $this->attempts() >= $this->tries) {
                    $this->record('failed', $e->getMessage());
                    return;
                }

                $this->record('retrying', 'Temporary provider rate limit.');
                $this->release(max(30, $e->retryAfter));
            } catch (Throwable) {
                // Avoid duplicate mail after an uncertain provider response.
                $this->record('needs_review', 'Unexpected mail sending outcome; manual review needed.');
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->record('failed', 'Email job exhausted retry attempts.');
    }

    private function record(string $status, ?string $error): void
    {
        DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
            'status' => $status, 'attempts' => $this->attempts(),
            'last_error' => $error, 'updated_at' => now(),
        ]);
    }
}
