<?php

namespace App\Services\Push;

use InvalidArgumentException;

class PushEndpointGuard
{
    public function check(string $endpoint, bool $resolve = true): void
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment']) || strlen($endpoint) > 2048) {
            throw new InvalidArgumentException('Invalid HTTPS push endpoint.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === '' || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost' || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            throw new InvalidArgumentException('Push endpoint host is not public.');
        }

        if (!$resolve) {
            return;
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!$records) {
            throw new InvalidArgumentException('Push endpoint DNS lookup failed.');
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!$ip || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('Push endpoint is not publicly routable.');
            }
        }

        // DNS can rebind between this lookup and the HTTP request. Enforce
        // outbound deny rules for private/reserved ranges at the host firewall.
    }
}
