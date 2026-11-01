<?php

namespace Tests\Feature;

use App\Jobs\SendRubikaNotification;
use App\Models\User;
use App\Services\RubikaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RubikaChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.rubika.token', 'test-token');
        config()->set('services.rubika.username', 'notification_hub_bot');
        config()->set('services.rubika.webhook_secret', str_repeat('c', 40));
        config()->set('cache.default', 'array');

        Http::fake([
            '*getChat' => Http::response([
                'status' => 'OK',
                'data' => ['chat' => ['chat_type' => 'User', 'user_id' => 'u0owner']],
            ], 200),
            '*sendMessage' => Http::response(['status' => 'OK', 'data' => ['message_id' => 'msg1']], 200),
            '*updateBotEndpoints' => Http::response(['status' => 'OK'], 200),
        ]);
    }

    private function startPayload(string $token, string $chatId = 'b0owner'): array
    {
        return [
            'update' => [
                'type' => 'StartedBot',
                'chat_id' => $chatId,
                'new_message' => [
                    'sender_type' => 'User',
                    'sender_id' => 'u0owner',
                    'aux_data' => ['start_id' => $token],
                ],
            ],
        ];
    }

    public function test_one_time_start_link_connects_private_chat(): void
    {
        $user = User::factory()->create();
        $url = $this->actingAs($user)
            ->postJson('/api/v1/channels/rubika/connect')->assertOk()->json('url');

        $this->assertStringContainsString('?st=', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $payload = $this->startPayload($params['st']);

        $this->postJson('/hooks/rubika/'.str_repeat('c', 40), $payload)->assertOk();

        $this->assertDatabaseHas('connections', [
            'user_id' => $user->id,
            'provider' => 'rubika',
            'external_id' => 'b0owner',
            'status' => 'active',
        ]);
        $this->postJson('/hooks/rubika/'.str_repeat('c', 40), $payload)->assertOk();
        $this->assertSame(1, DB::table('connections')->where('provider', 'rubika')->count());
        Http::assertSentCount(2); // getChat and a single welcome message
    }

    public function test_webhook_rejects_invalid_secret(): void
    {
        $this->postJson('/hooks/rubika/'.str_repeat('d', 40), $this->startPayload(str_repeat('a', 40)))
            ->assertNotFound();
    }

    public function test_refuses_group_connections(): void
    {
        Http::fake([
            '*getChat' => Http::response([
                'status' => 'OK', 'data' => ['chat' => ['chat_type' => 'Group', 'user_id' => null]],
            ], 200),
        ]);
        $user = User::factory()->create();
        $url = app(RubikaService::class)->connectionUrl($user);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->postJson('/hooks/rubika/'.str_repeat('c', 40), $this->startPayload($params['st'], 'g0group'))
            ->assertOk();
        $this->assertDatabaseCount('connections', 0);
    }

    public function test_chat_cannot_be_claimed_by_another_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        DB::table('connections')->insert([
            'user_id' => $owner->id, 'provider' => 'rubika', 'external_id' => 'b0owner',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = app(RubikaService::class)->connectionUrl($other);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->postJson('/hooks/rubika/'.str_repeat('c', 40), $this->startPayload($params['st']))
            ->assertOk();

        $this->assertDatabaseHas('connections', ['user_id' => $owner->id, 'external_id' => 'b0owner']);
        $this->assertDatabaseCount('connections', 1);
    }

    public function test_stop_event_marks_connection_blocked(): void
    {
        $user = User::factory()->create();
        DB::table('connections')->insert([
            'user_id' => $user->id, 'provider' => 'rubika',
            'external_id' => 'b0owner', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/hooks/rubika/'.str_repeat('c', 40), [
            'update' => ['type' => 'StoppedBot', 'chat_id' => 'b0owner'],
        ])->assertOk();
        $this->assertDatabaseHas('connections', ['user_id' => $user->id, 'status' => 'blocked']);
    }

    public function test_queue_delivery_uses_priority_and_stores_result(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $user->id, 'name' => 'CRM', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $connectionId = DB::table('connections')->insertGetId([
            'user_id' => $user->id, 'provider' => 'rubika', 'external_id' => 'b0owner',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $notificationId = DB::table('notifications')->insertGetId([
            'user_id' => $user->id, 'project_id' => $projectId, 'title' => 'CRM reminder',
            'body' => 'Due today', 'priority' => 1, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $deliveryId = app(RubikaService::class)->queueDelivery($notificationId, $connectionId);
        Bus::assertDispatched(SendRubikaNotification::class, fn ($job) => $job->queue === 'critical');

        (new SendRubikaNotification($deliveryId))->handle(app(RubikaService::class));
        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $deliveryId, 'status' => 'sent', 'channel' => 'rubika',
        ]);
    }

    public function test_connection_routes_require_authenticated_user(): void
    {
        $this->postJson('/api/v1/channels/rubika/connect')->assertUnauthorized();
    }
}
