<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GmailAdapter implements EmailAdapter
{
    public function __construct(private readonly EmailMimeFactory $mime) {}

    private function oauthConfig(): array
    {
        $id = (string) config('services.email_gateway.google_client_id');
        $secret = (string) config('services.email_gateway.google_client_secret');

        if ($id === '' || $secret === '') {
            throw new RuntimeException('Google OAuth is not configured on mail gateway.');
        }

        return [$id, $secret];
    }

    public function exchange(string $code, string $redirectUri): array
    {
        [$clientId, $clientSecret] = $this->oauthConfig();
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        $data = $response->json();
        if (!$response->successful() || !is_array($data) || empty($data['refresh_token'])
            || empty($data['access_token'])
            || !str_contains(' '.($data['scope'] ?? '').' ', ' https://www.googleapis.com/auth/gmail.send ')) {
            throw new MailGatewayException('Gmail authorization failed.');
        }

        return ['refresh_token' => $data['refresh_token'], 'access_token' => $data['access_token']];
    }

    public function refresh(string $refreshToken): string
    {
        [$clientId, $clientSecret] = $this->oauthConfig();
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        $data = $response->json();
        if (!$response->successful() || !is_array($data) || empty($data['access_token'])) {
            throw new MailGatewayException('Gmail refresh token is invalid or revoked.');
        }

        return $data['access_token'];
    }

    public function identity(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->timeout(12)
            ->get('https://openidconnect.googleapis.com/v1/userinfo');
        $identity = $response->json();
        if (!$response->successful() || !is_array($identity)
            || empty($identity['email']) || ($identity['email_verified'] ?? null) !== true
            || empty($identity['sub'])) {
            throw new MailGatewayException('Cannot verify Google mailbox ownership.');
        }

        return ['email' => strtolower($identity['email']), 'sub' => $identity['sub']];
    }

    public function revoke(string $refreshToken): void
    {
        $response = Http::asForm()->timeout(12)
            ->post('https://oauth2.googleapis.com/revoke', ['token' => $refreshToken]);
        if (!$response->successful()) {
            throw new MailGatewayException('Google token revocation failed.');
        }
    }

    public function send(array $account, array $message): array
    {
        $accessToken = $this->refresh($account['refresh_token']);
        $mime = $this->mime->create($message)->toString();
        $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

        $response = Http::withToken($accessToken)->acceptJson()->timeout(20)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $raw,
            ]);

        $json = $response->json();
        if (!$response->successful() || !is_array($json) || empty($json['id'])) {
            throw new MailGatewayException(
                'Gmail did not confirm message acceptance.',
                retryable: $response->status() === 429,
                indeterminate: $response->status() >= 500,
            );
        }

        return ['provider_id' => $json['id'], 'accepted' => true];
    }
}
