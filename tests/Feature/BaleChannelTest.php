<?php

namespace Tests\Feature;

use App\Jobs\SendBaleNotification;
use App\Models\User;
use App\Services\BaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BaleChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.bale.username', 'notification_hub_bot');
        config()->set('services.bale.token', 'test-token');
        config()->set('services.bale.webhook_secret', str_repeat('a', 40));
        config()->set('cache.default', 'array');
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    public function test_user_can_connect_private_chat_via_one_time_start_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $url = $this->postJson('/api/v1/channels/bale/connect')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $payload = [
            'update_id' => 1,
            'message' => [
                'text' => '/start '.$params['start'],
                'chat' => ['id' => 123456, 'type' => 'private'],
                'from' => ['id' => 123456],
            ],
        ];
        $this->postJson('/hooks/bale/'.str_repeat('a', 40), $payload)->assertOk();
        $this->assertDatabaseHas('connections', [
            'user_id' => $user->id, 'provider' => 'bale', 'external_id' => '123456', 'status' => 'active',
        ]);
        $this->postJson('/hooks/bale/'.str_repeat('a', 40), $payload)->assertOk();
        $this->assertEquals(1, DB::table('connections')->where('provider', 'bale')->count());
    }

    public function test_webhook_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/hooks/bale/'.str_repeat('b', 40), ['update_id' => 1])->assertNotFound();
    }

    public function test_cannot_take_another_users_private_chat(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        DB::table('connections')->insert([
            'user_id' => $owner->id, 'provider' => 'bale', 'external_id' => '123456', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = app(BaleService::class)->connectionUrl($other);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->postJson('/hooks/bale/'.str_repeat('a', 40), [
            'update_id' => 2,
            'message' => [
                'text' => '/start '.$params['start'],
                'chat' => ['id' => 123456, 'type' => 'private'],
                'from' => ['id' => 123456],
            ],
        ])->assertOk();
        $this->assertDatabaseHas('connections', ['user_id' => $owner->id, 'external_id' => '123456']);
        $this->assertEquals(1, DB::table('connections')->where('provider', 'bale')->count());
    }

    public function test_delivery_uses_priority_queue(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $user->id, 'name' => 'CRM', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $connectionId = DB::table('connections')->insertGetId([
            'user_id' => $user->id, 'provider' => 'bale', 'external_id' => '123456', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $notificationId = DB::table('notifications')->insertGetId([
            'user_id' => $user->id, 'project_id' => $projectId, 'title' => 'Server alert', 'priority' => 1,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(BaleService::class)->queueDelivery($notificationId, $connectionId);
        Bus::assertDispatched(SendBaleNotification::class, fn (SendBaleNotification $job) => $job->queue === 'critical');
    }
}
