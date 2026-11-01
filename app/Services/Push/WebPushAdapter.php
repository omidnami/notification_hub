<?php

namespace App\Services\Push;

class WebPushAdapter
{
    public function __construct(private readonly PushSender $sender) {}

    public function send(array $subscription, array $notification): array
    {
        return $this->sender->send($subscription, $notification);
    }
}
