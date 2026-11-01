<?php

namespace App\Services\Email;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use RuntimeException;

class SmtpAdapter implements EmailAdapter
{
    public function __construct(private readonly EmailMimeFactory $mime) {}

    public function send(array $account, array $message): array
    {
        $host = strtolower((string) ($account['host'] ?? ''));
        $port = (int) ($account['port'] ?? 0);
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || !in_array($port, [465, 587], true)
            || empty($account['username']) || empty($account['password'])) {
            throw new MailGatewayException('Invalid SMTP server configuration.');
        }

        // Best-effort DNS SSRF filter. Production gateway MUST also block private
        // destinations at the host firewall (including DNS rebinding).
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!$records) {
            throw new MailGatewayException('SMTP hostname did not resolve.');
        }
        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) $ips[] = $record['ip'];
            if (isset($record['ipv6'])) $ips[] = $record['ipv6'];
        }
        if (!$ips) {
            throw new MailGatewayException('SMTP hostname has no IP records.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new MailGatewayException('SMTP private or reserved destination blocked.');
            }
        }

        $dsn = new Dsn(
            $port === 465 ? 'smtps' : 'smtp',
            $host,
            $account['username'],
            $account['password'],
            $port,
            $port === 587 ? ['require_tls' => true] : [],
        );
        $mailer = new Mailer(Transport::fromDsn($dsn));
        try {
            $mailer->send($this->mime->create($message));
        } catch (\Throwable) {
            throw new MailGatewayException('SMTP provider did not confirm message acceptance.', indeterminate: true);
        }

        return ['accepted' => true]; // SMTP accepted, not final recipient delivery.
    }
}
