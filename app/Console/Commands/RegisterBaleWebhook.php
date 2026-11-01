<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RegisterBaleWebhook extends Command
{
    protected $signature = 'bale:webhook:set';
    protected $description = 'Register the authenticated Bale bot webhook';

    public function handle(): int
    {
        $token = (string) config('services.bale.token');
        $secret = (string) config('services.bale.webhook_secret');

        if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $secret)
            || !str_starts_with((string) config('app.url'), 'https://')) {
            $this->error('Configure BALE_BOT_TOKEN, BALE_WEBHOOK_SECRET (32+ characters), and an HTTPS APP_URL.');
            return self::FAILURE;
        }

        $url = route('bale.webhook', ['secret' => $secret]);

        try {
            $response = Http::timeout(15)->post('https://tapi.bale.ai/bot'.$token.'/setWebhook', ['url' => $url]);
            $body = $response->json();
            if (!$response->successful() || !is_array($body) || ($body['ok'] ?? false) !== true) {
                throw new RuntimeException('Bale rejected webhook registration.');
            }
        } catch (\Throwable $e) {
            $this->error('Webhook registration failed; check credentials, TLS and network.');
            return self::FAILURE;
        }

        $this->info('Bale webhook registered.');
        return self::SUCCESS;
    }
}
