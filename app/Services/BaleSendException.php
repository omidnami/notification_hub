<?php

namespace App\Services;

use RuntimeException;

class BaleSendException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code,
        public readonly int $retryAfter,
        public readonly bool $retryable,
    ) {
        parent::__construct($message, $code);
    }
}
