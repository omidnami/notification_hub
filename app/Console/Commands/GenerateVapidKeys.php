<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid:generate';
    protected $description = 'Print a new VAPID key pair for manual secure environment configuration';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->warn('Keep the private key secret. Never commit it to Git.');
        $this->line('WEBPUSH_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
