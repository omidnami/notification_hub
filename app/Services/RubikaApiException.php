<?php

namespace App\Services;

use RuntimeException;

class RubikaApiException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        public readonly bool $retryable = false,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message, $code);
    }
}
