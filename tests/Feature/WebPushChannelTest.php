<?php

namespace Tests\Feature;

use App\Jobs\SendWebPushNotification;
use App\Models\User;
use App\Services\Push\PushDispatchService;
use App\Services\Push\PushSendException;
use App\Services\Push\WebPushAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebPushChannelTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $deviceId, string $endpoint): array
    {
        return [
            'device_id' => $deviceId,
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => rtrim(strtr(base64_encode("\x04".str_repeat('A', 64)), '+/', '-_'), '='),
                'auth' => rtrim(strtr(base64_encode(str_repeat('B', 16)), '+/', '-_'), '='),
            ],
            'device_name' => 'Firefox on Linux',
            'browser' => 'Firefox',
            'platform' => 'Linux',
        ];
    }

    private function register(User $user, string $suffix, string $uuid): int
    {
        return (int) $this->actingAs($user)->postJson('/api/v1/push/devices',
            $this->payload($uuid, 'https://fcm.googleapis.com/fcm/send/'.$suffix))
            ->assertCreated()->json('device_id');
    }

    public function test_authentication_and_csrf_protect_device_registration(): void
    {
        $this->postJson('/api/v1/push/devices', $this->payload(
            '11111111-1111-4111-8111-111111111111',
            'https://fcm.googleapis.com/fcm/send/one'
        ))->assertUnauthorized();
    }

    public function test_multiple_devices_and_cross_account_claim_protection(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id1 = $this->register($owner, 'a', '11111111-1111-4111-8111-111111111111');
        $id2 = $this->register($owner, 'b', '22222222-2222-4222-8222-222222222222');

        $this->assertNotEquals($id1, $id2);
        $this->assertDatabaseCount('push_subscriptions', 2);

        $this->actingAs($other)->postJson('/api/v1/push/devices',
            $this->payload('33333333-3333-4333-8333-333333333333',
                'https://fcm.googleapis.com/fcm/send/a'))->assertStatus(422);
        $this->assertDatabaseCount('push_subscriptions', 2);
    }

    public function test_invalid_private_endpoint_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/push/devices',
            $this->payload('11111111-1111-4111-8111-111111111111',
                'https://localhost:8443/private'))->assertStatus(422);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_queues_only_notifications_own_devices(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id1 = $this->register($owner, 'a', '11111111-1111-4111-8111-111111111111');
        $id2 = $this->register($owner, 'b', '22222222-2222-4222-8222-222222222222');
        $this->register($other, 'c', '33333333-3333-4333-8333-333333333333');

        $id = DB::table('notifications')->insertGetId([
            'user_id' => $owner->id, 'title' => 'Alert', 'body' => 'Hello',
            'priority' => 2, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $deliveries = app(PushDispatchService::class)->queueNotification((int) $id);
        $this->assertCount(2, $deliveries);
        $this->assertEqualsCanonicalizing(
            [$id1, $id2],
            DB::table('notification_deliveries')->where('notification_id', $id)->pluck('push_subscription_id')->all()
        );
    }

    public function test_push_service_acceptance_is_not_marked_as_device_delivery(): void
    {
        $owner = User::factory()->create();
        $deviceId = $this->register($owner, 'a', '11111111-1111-4111-8111-111111111111');
        $id = DB::table('notifications')->insertGetId([
            'user_id' => $owner->id, 'title' => 'Reminder', 'body' => 'Hello',
            'priority' => 4, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $deliveryId = app(PushDispatchService::class)->queueNotification((int) $id, [$deviceId])[0];
        $sender = \Mockery::mock(WebPushAdapter::class);
        $sender->shouldReceive('send')->once()->andReturn(['accepted' => true]);

        (new SendWebPushNotification($deliveryId))->handle(
            $sender, app(\App\Services\Push\PushSubscriptionService::class)
        );
        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $deliveryId, 'status' => 'sent', 'channel' => 'webpush',
        ]);
        $this->assertNotNull(DB::table('push_subscriptions')->where('id', $deviceId)->value('last_success_at'));
    }

    public function test_expired_push_subscription_is_disabled(): void
    {
        $owner = User::factory()->create();
        $deviceId = $this->register($owner, 'a', '11111111-1111-4111-8111-111111111111');
        $id = DB::table('notifications')->insertGetId([
            'user_id' => $owner->id, 'title' => 'Reminder',
            'priority' => 4, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $deliveryId = app(PushDispatchService::class)->queueNotification((int) $id, [$deviceId])[0];

        $sender = \Mockery::mock(WebPushAdapter::class);
        $sender->shouldReceive('send')->once()->andThrow(new PushSendException(
            'Gone', httpStatus: 410, expired: true
        ));

        (new SendWebPushNotification($deliveryId))->handle(
            $sender, app(\App\Services\Push\PushSubscriptionService::class)
        );
        $this->assertDatabaseHas('push_subscriptions', ['id' => $deviceId, 'status' => 'expired']);
        $this->assertDatabaseHas('notification_deliveries', ['id' => $deliveryId, 'status' => 'failed']);
    }

    public function test_gateway_requires_valid_hmac(): void
    {
        config()->set('services.webpush.gateway_enabled', true);
        config()->set('services.webpush.gateway_secret', str_repeat('z', 48));
        $this->postJson('/internal/web-push', [])->assertUnauthorized();
    }

    public function test_project_push_api_prevents_cross_user_device_selection(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $device = $this->register($other, 'other', '44444444-4444-4444-8444-444444444444');
        $project = DB::table('projects')->insertGetId([
            'user_id' => $owner->id, 'name' => 'CRM', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = 'nh_'.str_repeat('X', 48);
        DB::table('api_keys')->insert([
            'project_id' => $project, 'name' => 'Test',
            'token_hash' => hash('sha256', $token),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/push/notifications', [
            'title' => 'Test', 'body' => 'Hello', 'device_ids' => [$device],
        ], [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => str_repeat('k', 32),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_project_api_queues_devices_and_deduplicates_requests(): void
    {
        $owner = User::factory()->create();
        $deviceId = $this->register($owner, 'owned', '11111111-1111-4111-8111-111111111111');

        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $owner->id, 'name' => 'CRM', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = 'nh_'.str_repeat('A', 48);
        DB::table('api_keys')->insert([
            'project_id' => $projectId, 'name' => 'Test project',
            'token_hash' => hash('sha256', $token),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => str_repeat('a', 32),
        ];
        $payload = [
            'title' => 'Task alert', 'body' => 'Due now',
            'url' => '/settings/notifications',
            'device_ids' => [$deviceId],
        ];

        $res = $this->postJson('/api/v1/push/notifications', $payload, $headers)->assertStatus(202);
        $id = $res->json('notification_id');
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $id, 'channel' => 'webpush', 'push_subscription_id' => $deviceId,
        ]);

        $this->postJson('/api/v1/push/notifications', $payload, $headers)
            ->assertOk()->assertJson(['notification_id' => $id, 'duplicate' => true]);
        $this->assertDatabaseCount('notification_deliveries', 1);

        $this->getJson('/api/v1/push/notifications/'.$id, $headers)
            ->assertOk()->assertJson(['final_device_delivery_confirmed' => false]);
    }

    public function test_project_api_blocks_external_notification_link(): void
    {
        $owner = User::factory()->create();
        $this->register($owner, 'owned', '11111111-1111-4111-8111-111111111111');
        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $owner->id, 'name' => 'CRM', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = 'nh_'.str_repeat('A', 48);
        DB::table('api_keys')->insert([
            'project_id' => $projectId, 'name' => 'Test project',
            'token_hash' => hash('sha256', $token),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/push/notifications', [
            'title' => 'Test', 'body' => 'Test',
            'url' => 'https://attacker.example/redirect',
        ], [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => str_repeat('b', 32),
        ])->assertStatus(422);
        $this->assertDatabaseCount('notifications', 0);
    }

}
