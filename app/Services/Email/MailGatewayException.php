<?php

namespace App\Services\Email;

use RuntimeException;

class MailGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly int $retryAfter = 0,
        public readonly bool $indeterminate = false,
    ) {
        parent::__construct($message);
    }
}
