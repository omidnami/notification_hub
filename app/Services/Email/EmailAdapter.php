<?php

namespace App\Services\Email;

interface EmailAdapter
{
    public function send(array $account, array $message): array;
}
