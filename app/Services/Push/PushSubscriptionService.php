<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PushSubscriptionService
{
    public function __construct(private readonly PushEndpointGuard $endpoints) {}

    public function register(int $userId, array $input): int
    {
        $endpoint = $input['endpoint'];
        $this->endpoints->check($endpoint, resolve: false);

        foreach (['p256dh' => 65, 'auth' => 16] as $key => $size) {
            $value = $input['keys'][$key];
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
                throw new InvalidArgumentException('Invalid push subscription key.');
            }
            $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
            if ($decoded === false || strlen($decoded) !== $size) {
                throw new InvalidArgumentException('Invalid push subscription key length.');
            }
        }

        $deviceId = $input['device_id'];
        if (!Str::isUuid($deviceId)) {
            throw new InvalidArgumentException('Invalid device ID.');
        }

        $hash = hash('sha256', $endpoint);
        $subscription = [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $input['keys']['p256dh'], 'auth' => $input['keys']['auth']],
            'contentEncoding' => $input['content_encoding'] ?? 'aes128gcm',
        ];

        return DB::transaction(function () use ($userId, $deviceId, $hash, $subscription, $input): int {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id']);

            $owner = DB::table('push_subscriptions')->where('endpoint_hash', $hash)->first();
            if ($owner && (int) $owner->user_id !== $userId) {
                throw new InvalidArgumentException('Subscription belongs to another account.');
            }

            $device = DB::table('push_subscriptions')
                ->where('user_id', $userId)->where('device_id', $deviceId)->first();
            if ($device && $owner && (int) $owner->id !== (int) $device->id) {
                throw new InvalidArgumentException('Subscription is already registered to another device.');
            }

            if (!$device && !$owner && DB::table('push_subscriptions')->where('user_id', $userId)->count() >= 20) {
                throw new InvalidArgumentException('Maximum 20 devices per user.');
            }

            // A subscription may rotate its endpoint on the same physical device.
            $values = [
                'endpoint_hash' => $hash,
                'subscription' => Crypt::encryptString(json_encode($subscription, JSON_THROW_ON_ERROR)),
                'status' => 'active',
                'device_name' => $input['device_name'] ?? null,
                'platform' => $input['platform'] ?? null,
                'browser' => $input['browser'] ?? null,
                'last_error' => null,
                'updated_at' => now(),
            ];

            if ($device) {
                DB::table('push_subscriptions')->where('id', $device->id)->update($values);
                return (int) $device->id;
            }
            if ($owner) {
                DB::table('push_subscriptions')->where('id', $owner->id)
                    ->update($values + ['device_id' => $deviceId]);
                return (int) $owner->id;
            }

            return (int) DB::table('push_subscriptions')->insertGetId($values + [
                'user_id' => $userId,
                'device_id' => $deviceId,
                'created_at' => now(),
            ]);
        });
    }

    public function data(object $record): array
    {
        return json_decode(Crypt::decryptString($record->subscription), true, 6, JSON_THROW_ON_ERROR);
    }

    public function ownedDevice(int $userId, int $deviceId): object
    {
        $device = DB::table('push_subscriptions')
            ->where('user_id', $userId)->where('id', $deviceId)->first();

        if (!$device) {
            throw new InvalidArgumentException('Device not found.');
        }
        return $device;
    }
}
