<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotApi;
use Illuminate\Console\Command;
use Throwable;

class RegisterTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook:set';
    protected $description = 'Register the central Telegram webhook on the Germany worker';

    public function handle(TelegramBotApi $telegram): int
    {
        $url = (string) config('services.telegram.webhook_url');
        $secret = (string) config('services.telegram.webhook_secret');

        if (config('services.telegram.role') !== 'worker'
            || !preg_match('~^https://[A-Za-z0-9.-]+/hooks/telegram$~', $url)
            || !preg_match('/^[A-Za-z0-9_-]{32,256}$/', $secret)
            || !config('services.telegram.token')) {
            $this->error('Set TELEGRAM_ROLE=worker, TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_URL and TELEGRAM_WEBHOOK_SECRET.');
            return self::FAILURE;
        }

        try {
            $response = $telegram->setWebhook($url, $secret);
            if (($response['accepted'] ?? false) !== true) {
                throw new \RuntimeException('Telegram did not accept webhook.');
            }
        } catch (Throwable) {
            // Do not leak bot tokens or provider URLs to logs/console.
            $this->error('Telegram webhook registration failed. Check HTTPS, token and network.');
            return self::FAILURE;
        }

        $this->info('Telegram webhook registered for messages and bot membership changes.');

        return self::SUCCESS;
    }
}
