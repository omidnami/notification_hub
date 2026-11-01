<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GatewayClient
{
    public function call(string $action, array $payload): array
    {
        $url = rtrim((string) config('services.email_gateway.url'), '/');
        $secret = (string) config('services.email_gateway.secret');

        if (!str_starts_with($url, 'https://') || strlen($secret) < 32) {
            throw new MailGatewayException('Mail gateway is not configured.');
        }

        $body = json_encode(['action' => $action, 'payload' => $payload], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = Str::random(40);
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$body, $secret);

        try {
            $response = Http::timeout(27)->withHeaders([
                'X-NH-Time' => $timestamp,
                'X-NH-Nonce' => $nonce,
                'X-NH-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])->withBody($body, 'application/json')->post($url.'/internal/mail-gateway');
        } catch (Throwable) {
            // A timeout may occur after a provider accepted the message.
            throw new MailGatewayException('Mail gateway request outcome is unknown.', indeterminate: true);
        }

        $json = $response->json();
        if (!$response->successful() || !is_array($json) || ($json['ok'] ?? null) !== true) {
            $retryAfter = min(3600, max(0, (int) $response->header('Retry-After', 0)));
            throw new MailGatewayException(
                'Mail gateway rejected request (HTTP '.$response->status().').',
                retryable: $response->status() === 429,
                retryAfter: $retryAfter,
                indeterminate: $response->status() >= 500,
            );
        }

        return is_array($json['result'] ?? null) ? $json['result'] : [];
    }
}
