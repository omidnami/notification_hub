<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class PushSender
{
    public function send(array $subscription, array $notification): array
    {
        if (config('services.webpush.use_gateway')) {
            return $this->sendViaGateway($subscription, $notification);
        }

        return $this->sendDirect($subscription, $notification);
    }

    public function sendDirect(array $subscription, array $notification): array
    {
        $public = (string) config('services.webpush.public_key');
        $private = (string) config('services.webpush.private_key');
        $subject = (string) config('services.webpush.subject');

        if ($public === '' || $private === '' ||
            !(str_starts_with($subject, 'mailto:') || str_starts_with($subject, 'https://'))) {
            throw new PushSendException('VAPID is not configured.');
        }

        try {
            app(PushEndpointGuard::class)->check((string) ($subscription['endpoint'] ?? ''));

            $webPush = new WebPush(['VAPID' => [
                'subject' => $subject,
                'publicKey' => $public,
                'privateKey' => $private,
            ]], ['TTL' => 3600, 'urgency' => 'normal', 'contentType' => 'application/json']);

            $json = json_encode($notification, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($json) > 2700) {
                throw new PushSendException('Push payload exceeds safe size.');
            }

            $report = $webPush->sendOneNotification(Subscription::create($subscription), $json);
        } catch (PushSendException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException) {
            throw new PushSendException('Unsafe or unreachable push endpoint.');
        } catch (Throwable) {
            throw new PushSendException('Push transport outcome is unknown.', indeterminate: true);
        }

        if ($report->isSuccess()) {
            return ['accepted' => true];
        }

        $response = $report->getResponse();
        $status = $response?->getStatusCode() ?? 0;
        $expired = $report->isSubscriptionExpired() || in_array($status, [404, 410], true);
        $retryAfter = $response ? (int) $response->getHeaderLine('Retry-After') : 0;

        throw new PushSendException(
            'Push service refused message (HTTP '.$status.').',
            httpStatus: $status,
            retryable: $status === 429 || $status >= 500,
            retryAfter: min(3600, max(0, $retryAfter)),
            expired: $expired,
            indeterminate: $status === 0,
        );
    }

    private function sendViaGateway(array $subscription, array $notification): array
    {
        $url = rtrim((string) config('services.webpush.gateway_url'), '/');
        $secret = (string) config('services.webpush.gateway_secret');
        if (!str_starts_with($url, 'https://') || strlen($secret) < 32) {
            throw new PushSendException('Web Push gateway is not configured.');
        }

        $body = json_encode([
            'subscription' => $subscription,
            'notification' => $notification,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $time = (string) time();
        $nonce = Str::random(40);
        $signature = hash_hmac('sha256', $time."\n".$nonce."\n".$body, $secret);

        try {
            $response = Http::timeout(28)->withHeaders([
                'X-NH-Time' => $time,
                'X-NH-Nonce' => $nonce,
                'X-NH-Signature' => $signature,
            ])->withBody($body, 'application/json')
                ->post($url.'/internal/web-push');
        } catch (Throwable) {
            throw new PushSendException('Gateway outcome is unknown.', indeterminate: true);
        }

        $data = $response->json();
        if ($response->successful() && ($data['accepted'] ?? false) === true) {
            return ['accepted' => true, 'gateway' => true];
        }

        $status = $response->status();
        $code = (int) ($data['push_status'] ?? 0);
        throw new PushSendException(
            'Web Push gateway rejected delivery.',
            httpStatus: $code,
            retryable: $status === 429,
            retryAfter: min(3600, max(0, (int) $response->header('Retry-After', 0))),
            expired: $status === 410 || ($data['expired'] ?? false) === true,
            indeterminate: $status >= 500,
        );
    }
}
