<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTelegramWelcome implements ShouldQueue
{
    use Queueable;

    // A welcome message is nonessential; avoid duplicate sends on uncertain results.
    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public readonly string $chatId) {}

    public function handle(TelegramService $telegram): void
    {
        $stillLinked = DB::table('connections')->where('provider', 'telegram')
            ->where('external_id', $this->chatId)->where('status', 'active')->exists();
        if (!$stillLinked) {
            return;
        }

        try {
            $telegram->sendMessage($this->chatId, 'حساب تلگرام شما به Notification Hub متصل شد.');
        } catch (Throwable) {
            Log::warning('Telegram connection succeeded but welcome was not accepted.');
        }
    }
}
