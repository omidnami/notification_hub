<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramInternalClient
{
    public function post(string $target, array $payload): Response
    {
        $base = (string) config($target === 'core'
            ? 'services.telegram.core_url' : 'services.telegram.gateway_url');
        $secret = (string) config('services.telegram.gateway_secret');
        if (!in_array($target, ['core', 'gateway'], true)
            || !preg_match('~^https://[^/?#]+(?:/)?$~', $base)
            || strlen($secret) < 32) {
            throw new RuntimeException('Telegram secure gateway is not configured.');
        }

        $path = $target === 'core' ? '/internal/telegram/events' : '/internal/telegram/send';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $time = (string) time();
        $nonce = Str::random(40);
        $mac = hash_hmac('sha256', $time."\n".$nonce."\n".$path."\n".$body, $secret);

        return Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(18)
            ->withHeaders([
                'X-NH-Telegram-Time' => $time,
                'X-NH-Telegram-Nonce' => $nonce,
                'X-NH-Telegram-Signature' => $mac,
            ])->withBody($body, 'application/json')
            ->post(rtrim($base, '/').$path);
    }
}
