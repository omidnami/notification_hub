<?php

namespace App\Services\Telegram;

use RuntimeException;

class TelegramException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly bool $retryable = false,
        public readonly int $retryAfter = 0,
        public readonly bool $indeterminate = false,
    ) {
        parent::__construct($message);
    }
}
