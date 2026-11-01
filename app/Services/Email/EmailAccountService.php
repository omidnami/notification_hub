<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EmailAccountService
{
    public function credentials(object $connection): array
    {
        $data = json_decode(Crypt::decryptString($connection->credentials), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid account credentials.');
        }
        return $data;
    }

    public function save(
        int $userId,
        string $provider,
        string $email,
        array $credentials,
        string $status = 'active',
    ): int {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($provider, ['gmail', 'smtp'], true)) {
            throw new InvalidArgumentException('Invalid email account.');
        }

        return DB::transaction(function () use ($userId, $provider, $email, $credentials, $status): int {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id']);

            $existing = DB::table('connections')->where('provider', $provider)
                ->where('external_id', $email)->first();

            if ($existing && (int) $existing->user_id !== $userId) {
                throw new InvalidArgumentException('Email is already connected to another account.');
            }

            $values = [
                'user_id' => $userId,
                'provider' => $provider,
                'external_id' => $email,
                'credentials' => Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)),
                'status' => $status,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('connections')->where('id', $existing->id)->update($values);
                return (int) $existing->id;
            }

            return (int) DB::table('connections')->insertGetId($values + ['created_at' => now()]);
        });
    }

    public function getOwned(int $userId, int $connectionId): object
    {
        $connection = DB::table('connections')->where('id', $connectionId)
            ->where('user_id', $userId)->whereIn('provider', ['gmail', 'smtp'])->first();

        if (!$connection) {
            throw new InvalidArgumentException('Email account does not exist.');
        }

        return $connection;
    }
}
