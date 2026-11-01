<?php

namespace App\Console\Commands;

use App\Services\RubikaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterRubikaWebhook extends Command
{
    protected $signature = 'rubika:webhook:set';
    protected $description = 'Register the Rubika ReceiveUpdate endpoint';

    public function handle(RubikaService $rubika): int
    {
        $secret = (string) config('services.rubika.webhook_secret');

        if (!config('services.rubika.token')
            || !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $secret)
            || !str_starts_with((string) config('app.url'), 'https://')) {
            $this->error('Configure RUBIKA_BOT_TOKEN, RUBIKA_WEBHOOK_SECRET and HTTPS APP_URL.');
            return self::FAILURE;
        }

        try {
            $rubika->request('updateBotEndpoints', [
                'url' => route('rubika.webhook', ['secret' => $secret]),
                'type' => 'ReceiveUpdate',
            ]);
        } catch (\Throwable) {
            $this->error('Rubika webhook registration failed; verify network and bot settings.');
            return self::FAILURE;
        }

        $this->info('Rubika ReceiveUpdate webhook registered.');
        return self::SUCCESS;
    }
}
