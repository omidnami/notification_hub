<?php

namespace App\Services;

use InvalidArgumentException;

class EitaaInitData
{
    public function verifiedUser(string $initData): array
    {
        $token = (string) config('services.eitaa.token');
        if ($token === '' || strlen($initData) > 8192 || $initData === '') {
            throw new InvalidArgumentException('Invalid Eitaa authorization data.');
        }

        parse_str($initData, $data);
        $received = $data['hash'] ?? null;
        $authDate = $data['auth_date'] ?? null;
        $userData = $data['user'] ?? null;

        if (!is_string($received) || !preg_match('/^[a-fA-F0-9]{64}$/', $received)
            || !is_string($authDate) || !ctype_digit($authDate)
            || !is_string($userData)) {
            throw new InvalidArgumentException('Invalid Eitaa authorization data.');
        }

        $timestamp = (int) $authDate;
        if ($timestamp > time() + 60 || $timestamp < time() - 300) {
            throw new InvalidArgumentException('Expired Eitaa authorization data.');
        }

        unset($data['hash']);
        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidArgumentException('Invalid Eitaa authorization data.');
            }
        }

        ksort($data, SORT_STRING);
        $pairs = [];
        foreach ($data as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        // Based on eitaa/initdata-validation-examples (official PHP example).
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $calculated = hash_hmac('sha256', implode("\n", $pairs), $secret);

        if (!hash_equals($calculated, strtolower($received))) {
            throw new InvalidArgumentException('Invalid Eitaa authorization signature.');
        }

        try {
            $user = json_decode($userData, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid Eitaa user data.');
        }

        if (!is_array($user)
            || !is_int($user['id'] ?? null)
            || $user['id'] <= 0
            || ($user['allows_write_to_pm'] ?? null) !== true) {
            throw new InvalidArgumentException('Eitaa user has not granted message permission.');
        }

        return $user;
    }
}
