<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

class TelegramBotApi
{
    public function sendMessage(string $chatId, string $text): array
    {
        if (config('services.telegram.role') !== 'worker') {
            throw new RuntimeException('Telegram API is only available on the Germany worker.');
        }
        if (!preg_match('/^[1-9][0-9]{0,18}$/', $chatId)
            || trim($text) === '' || mb_strlen($text) > 4096) {
            throw new TelegramException('Invalid private chat or Telegram message.', 422);
        }

        // Shared atomic cache is required when there are multiple worker processes.
        Cache::lock('telegram:send:rate-lock', 5)->block(3, function () use ($chatId): void {
            $global = 'telegram:rate:global';
            $personal = 'telegram:rate:chat:'.$chatId;
            if (RateLimiter::tooManyAttempts($global, 25)
                || RateLimiter::tooManyAttempts($personal, 1)) {
                $wait = max(1, RateLimiter::availableIn($global), RateLimiter::availableIn($personal));
                throw new TelegramException('Local Telegram rate limit reached.', 429, true, $wait);
            }
            RateLimiter::hit($global, 1);
            RateLimiter::hit($personal, 1);
        });

        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'my_chat_member'],
            'max_connections' => 10,
            'drop_pending_updates' => false,
        ]);
    }

    public function call(string $method, array $parameters): array
    {
        if (config('services.telegram.role') !== 'worker') {
            throw new RuntimeException('Telegram Bot API access restricted to worker.');
        }
        $token = (string) config('services.telegram.token');
        if ($token === '') {
            throw new RuntimeException('Telegram bot token not configured.');
        }

        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->post('https://api.telegram.org/bot'.$token.'/'.$method, $parameters);
        } catch (Throwable) {
            throw new TelegramException('Telegram transport result unknown.', 0, false, 0, true);
        }

        $json = $response->json();
        $code = (int) ($json['error_code'] ?? $response->status());
        $retryAfter = max(1, min(3600, (int) ($json['parameters']['retry_after']
            ?? $response->header('Retry-After', 1))));

        if (!$response->successful() || !is_array($json) || ($json['ok'] ?? false) !== true) {
            throw new TelegramException(
                'Telegram rejected request.',
                $code,
                $code === 429,
                $code === 429 ? $retryAfter : 0,
                $code >= 500 || $code === 0,
            );
        }

        $result = $json['result'] ?? null;
        if ($method === 'sendMessage' && (!is_array($result) || !isset($result['message_id']))) {
            throw new TelegramException('Telegram acceptance uncertain.', 0, false, 0, true);
        }

        return is_array($result) ? $result : ['accepted' => $result === true];
    }
}
