<?php

namespace App\Services\Ippanel;

use Illuminate\Support\Facades\Http;
use Throwable;

class IppanelAdapter
{
    private const ROOT = 'https://edge.ippanel.com/v1';

    public function send(string $channel, array $details): array
    {
        $to = (string) ($details['to'] ?? '');
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $to)) {
            throw new IppanelException('Invalid E.164 destination.');
        }

        $body = match ($channel) {
            'sms' => $this->smsPayload($details, $to),
            'voice_otp' => $this->voicePayload($details, $to),
            default => throw new IppanelException('Unsupported IPPanel channel.'),
        };

        return $this->request('POST', '/api/send', $body);
    }

    public function credit(): array
    {
        return $this->request('GET', '/api/payment/credit/mine');
    }

    private function smsPayload(array $details, string $to): array
    {
        $from = (string) config('services.ippanel.from_number');
        if (!preg_match('/^\+[1-9]\d{4,14}$/', $from)) {
            throw new IppanelException('IPPanel sender number is not configured.');
        }

        if (($details['type'] ?? '') === 'pattern') {
            return [
                'sending_type' => 'pattern',
                'from_number' => $from,
                'code' => $details['code'],
                'recipients' => [$to],
                'params' => $details['params'] ?? [],
            ];
        }

        return [
            'sending_type' => 'webservice',
            'from_number' => $from,
            'message' => $details['message'],
            'params' => ['recipients' => [$to]],
        ];
    }

    private function voicePayload(array $details, string $to): array
    {
        $code = (string) ($details['code'] ?? '');
        if (!preg_match('/^\d{4,8}$/', $code)) {
            throw new IppanelException('Voice OTP must contain 4–8 digits.');
        }

        return [
            'sending_type' => 'votp',
            'message' => $code,
            'params' => ['recipients' => [$to]],
        ];
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $apiKey = (string) config('services.ippanel.key');
        if ($apiKey === '') {
            throw new IppanelException('IPPanel API key is not configured.');
        }

        try {
            $client = Http::acceptJson()->timeout(15)->connectTimeout(5)
                ->withHeaders(['Authorization' => $apiKey]);

            $response = $method === 'GET'
                ? $client->get(self::ROOT.$path)
                : $client->post(self::ROOT.$path, $body);
        } catch (Throwable) {
            throw new IppanelException('IPPanel request result is unknown.', indeterminate: true);
        }

        $data = $response->json();
        if (!$response->successful() || !is_array($data) || ($data['meta']['status'] ?? null) !== true) {
            $status = $response->status();

            throw new IppanelException(
                'IPPanel rejected request (HTTP '.$status.').',
                retryable: $status === 429,
                retryAfter: min(3600, max(0, (int) $response->header('Retry-After', 0))),
                indeterminate: $status >= 500,
            );
        }

        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }
}
