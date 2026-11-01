<?php

namespace Tests\Feature;

use App\Jobs\SendIppanelNotification;
use App\Jobs\SendOutgoingWebhook;
use App\Models\User;
use App\Services\Ippanel\IppanelAdapter;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationCoreTest extends TestCase
{
    use RefreshDatabase;

    private function client(): array
    {
        $user = User::factory()->create();
        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $user->id, 'name' => 'CRM', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $key = 'nh_'.str_repeat('K', 48);
        DB::table('api_keys')->insert([
            'project_id' => $projectId, 'name' => 'CRM Integration',
            'token_hash' => hash('sha256', $key),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $projectId, [
            'Authorization' => 'Bearer '.$key,
            'Idempotency-Key' => str_repeat('d', 32),
        ]];
    }

    public function test_notification_create_replay_edit_read_delete_and_delivery_history(): void
    {
        Bus::fake();
        [$user, $projectId, $headers] = $this->client();
        $payload = ['title' => 'CRM task', 'body' => 'Due today', 'channels' => ['in_app']];

        $first = $this->postJson('/api/v1/notifications', $payload, $headers)->assertCreated();
        $id = $first->json('id');

        $this->postJson('/api/v1/notifications', $payload, $headers)
            ->assertOk()->assertJson(['duplicate' => true, 'id' => $id]);

        $this->postJson('/api/v1/notifications', [
            'title' => 'Changed', 'body' => 'Due today',
        ], $headers)->assertStatus(409);

        $this->patchJson('/api/v1/notifications/'.$id, ['title' => 'Updated'], $headers)
            ->assertOk();
        $this->patchJson('/api/v1/notifications/'.$id.'/read', [], $headers)
            ->assertOk();

        DB::table('notification_deliveries')->insert([
            'notification_id' => $id, 'channel' => 'in_app',
            'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson('/api/v1/notifications/'.$id, [], $headers)
            ->assertOk();
        $this->getJson('/api/v1/notifications/'.$id, $headers)->assertNotFound();

        $this->assertDatabaseHas('notifications', ['id' => $id, 'title' => 'Updated']);
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('deleted_at'));
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $id, 'status' => 'sent']);
    }

    public function test_project_and_user_inbox_are_isolated(): void
    {
        Bus::fake();
        [$owner, $projectId, $headers] = $this->client();
        $id = $this->postJson('/api/v1/notifications', [
            'title' => 'Private', 'channels' => ['in_app'],
        ], $headers)->assertCreated()->json('id');

        [$stranger, $foreignProject, $foreignHeaders] = $this->client();
        $this->getJson('/api/v1/notifications/'.$id, $foreignHeaders)->assertNotFound();
        $this->actingAs($stranger)
            ->getJson('/api/v1/inbox/'.$id)->assertNotFound();
        $this->getJson('/api/v1/inbox')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($owner)->getJson('/api/v1/inbox/'.$id)
            ->assertOk()->assertJsonPath('notification.title', 'Private');
    }

    public function test_sms_quota_and_consent_checks_are_applied(): void
    {
        Bus::fake();
        [$user, $projectId, $headers] = $this->client();
        config()->set('services.ippanel.project_daily_limit', 1);
        config()->set('services.ippanel.user_daily_limit', 2);
        config()->set('services.ippanel.recipient_hourly_limit', 5);

        $base = [
            'title' => 'Status', 'channels' => ['sms'],
            'sms' => ['type' => 'text', 'to' => '+989120000000', 'message' => 'Test', 'consent' => true],
        ];

        $id = $this->postJson('/api/v1/notifications', $base, $headers)
            ->assertCreated()->json('id');

        $this->assertDatabaseHas('notification_channel_payloads', [
            'notification_id' => $id, 'channel' => 'sms',
        ]);
        $encrypted = DB::table('notification_channel_payloads')
            ->where('notification_id', $id)->value('payload');
        $this->assertStringNotContainsString('+989120000000', $encrypted);

        $headers['Idempotency-Key'] = str_repeat('e', 32);
        $this->postJson('/api/v1/notifications', $base, $headers)
            ->assertStatus(422);

        $base['sms']['consent'] = false;
        $headers['Idempotency-Key'] = str_repeat('f', 32);
        $this->postJson('/api/v1/notifications', $base, $headers)
            ->assertStatus(422);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_ippanel_adapter_uses_documented_voice_payload_and_success_response(): void
    {
        config()->set('services.ippanel.key', 'fake-test-key');
        Http::fake(['*edge.ippanel.com/*' => Http::response([
            'data' => ['message_outbox_ids' => [1123594208]],
            'meta' => ['status' => true, 'message_code' => '200-1'],
        ], 200)]);

        $result = app(IppanelAdapter::class)->send('voice_otp', [
            'to' => '+989120000000', 'code' => '45852',
        ]);
        $this->assertSame([1123594208], $result['message_outbox_ids']);
        Http::assertSent(fn ($r) => $r->url() === 'https://edge.ippanel.com/v1/api/send'
            && $r['sending_type'] === 'votp'
            && $r['message'] === '45852'
            && $r['params']['recipients'] === ['+989120000000']);
    }

    public function test_ippanel_adapter_uses_documented_pattern_payload(): void
    {
        config()->set('services.ippanel.key', 'fake-test-key');
        config()->set('services.ippanel.from_number', '+983000505');
        Http::fake(['*edge.ippanel.com/*' => Http::response([
            'data' => ['message_outbox_ids' => [123]],
            'meta' => ['status' => true],
        ], 200)]);

        app(IppanelAdapter::class)->send('sms', [
            'type' => 'pattern', 'to' => '+989120000000',
            'code' => 'approved', 'params' => ['code' => '1234'],
        ]);

        Http::assertSent(fn ($r) =>
            $r['sending_type'] === 'pattern'
            && $r['from_number'] === '+983000505'
            && $r['code'] === 'approved'
            && $r['recipients'] === ['+989120000000']
        );
    }

    public function test_webhook_config_is_tenant_owned_and_secret_is_encrypted(): void
    {
        Bus::fake();
        [$owner, $projectId, $headers] = $this->client();

        $guard = \Mockery::mock(UrlGuard::class);
        $guard->shouldReceive('check')->once()->with('https://hooks.example.com/events');
        app()->instance(UrlGuard::class, $guard);

        $res = $this->actingAs($owner)->postJson('/api/v1/webhooks', [
            'project_id' => $projectId,
            'url' => 'https://hooks.example.com/events',
            'events' => ['notification.created'],
        ])->assertCreated();

        $id = $res->json('id');
        $secret = $res->json('secret');
        $cipher = DB::table('webhooks')->where('id', $id)->value('secret');
        $this->assertSame($secret, Crypt::decryptString($cipher));
        $this->assertStringNotContainsString($secret, $cipher);

        $this->postJson('/api/v1/notifications', [
            'title' => 'Event', 'channels' => ['in_app'],
        ], $headers)->assertCreated();
        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $id, 'event' => 'notification.created', 'status' => 'pending',
        ]);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson('/api/v1/webhooks?project_id='.$projectId)
            ->assertNotFound();
    }

    public function test_inbox_sse_requires_login(): void
    {
        $this->getJson('/api/v1/inbox/stream')->assertUnauthorized();
        [$user, $projectId] = $this->client();
        $this->actingAs($user)->get('/api/v1/inbox/stream')
            ->assertOk()->assertHeader('Content-Type', 'text/event-stream');
    }

    public function test_webhook_delivery_is_signed_and_event_log_is_preserved(): void
    {
        $user = User::factory()->create();
        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $user->id, 'name' => 'Integration',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $secret = 'test-secret';
        $hookId = DB::table('webhooks')->insertGetId([
            'project_id' => $projectId, 'url' => 'https://receiver.example.org/hook',
            'secret' => Crypt::encryptString($secret),
            'events' => json_encode(['notification.created']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = json_encode([
            'id' => 'test-event-1',
            'type' => 'notification.created',
            'project_id' => $projectId,
        ]);
        $deliveryId = DB::table('webhook_deliveries')->insertGetId([
            'webhook_id' => $hookId, 'notification_id' => null,
            'event' => 'notification.created',
            'event_key' => hash('sha256', 'test-event-1'),
            'payload' => Crypt::encryptString($payload),
            'status' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $guard = \Mockery::mock(UrlGuard::class);
        $guard->shouldReceive('check')->once()->with('https://receiver.example.org/hook');
        app()->instance(UrlGuard::class, $guard);

        Http::fake(['*receiver.example.org/*' => Http::response(['ok' => true], 204)]);
        (new SendOutgoingWebhook($deliveryId))->handle($guard);

        $this->assertDatabaseHas('webhook_deliveries', [
            'id' => $deliveryId, 'status' => 'sent', 'http_status' => 204,
        ]);

        Http::assertSent(function ($request) use ($secret, $payload): bool {
            $timestamp = $request->header('X-NotificationHub-Time')[0] ?? '';
            return $request->url() === 'https://receiver.example.org/hook'
                && $request->body() === $payload
                && $request->hasHeader(
                    'X-NotificationHub-Signature',
                    'sha256='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
                );
        });
    }

    public function test_successful_ippanel_job_records_only_provider_acceptance(): void
    {
        Bus::fake();
        [$user, $projectId, $headers] = $this->client();
        config()->set('services.ippanel.key', 'fake-key');
        config()->set('services.ippanel.from_number', '+983000505');
        Http::fake(['*edge.ippanel.com/*' => Http::response([
            'data' => ['message_outbox_ids' => [123456]],
            'meta' => ['status' => true],
        ])]);

        $id = $this->postJson('/api/v1/notifications', [
            'title' => 'SMS status',
            'channels' => ['sms'],
            'sms' => [
                'type' => 'text', 'to' => '+989120000000',
                'message' => 'Service update', 'consent' => true,
            ],
        ], $headers)->assertCreated()->json('id');

        // RefreshDatabase holds an outer transaction: afterCommit fan-out does
        // not run in this test. Build its queued delivery explicitly.
        $deliveryId = DB::table('notification_deliveries')->insertGetId([
            'notification_id' => $id, 'channel' => 'sms',
            'status' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        (new SendIppanelNotification((int) $deliveryId))->handle(app(IppanelAdapter::class));

        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $deliveryId, 'status' => 'sent',
        ]);
        $this->assertDatabaseHas('notifications', ['id' => $id, 'status' => 'pending']);
    }

}
