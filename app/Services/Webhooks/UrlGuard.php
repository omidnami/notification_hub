<?php

namespace App\Services\Webhooks;

use InvalidArgumentException;

class UrlGuard
{
    public function check(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['port']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['fragment']) || strlen($url) > 2048) {
            throw new InvalidArgumentException('Webhook URL must be an HTTPS hostname without custom port.');
        }

        $hostname = strtolower(rtrim($parts['host'], '.'));
        if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($hostname, FILTER_VALIDATE_IP) !== false
            || $hostname === 'localhost' || str_ends_with($hostname, '.local')
            || str_ends_with($hostname, '.internal')) {
            throw new InvalidArgumentException('Webhook host must be public.');
        }

        $records = dns_get_record($hostname, DNS_A | DNS_AAAA);
        if (!$records) {
            throw new InvalidArgumentException('Cannot resolve webhook hostname.');
        }
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!$address || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('Webhook resolves to a restricted IP.');
            }
        }

        // Production server MUST ALSO firewall-deny outbound private/reserved IP ranges.
        // DNS can rebind between validation and the HTTP connection.
    }
}
