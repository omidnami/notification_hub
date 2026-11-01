<?php

namespace Tests\Feature;

use App\Jobs\SendEitaaNotification;
use App\Models\User;
use App\Services\EitaaApiException;
use App\Services\EitaaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EitaaChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.eitaa.token', 'eitaa-test-token');
        config()->set('services.eitaa.app_url', 'https://eitaa.com/notification_hub_app');
        config()->set('services.eitaa.message_budget', 50000);
        config()->set('cache.default', 'array');
        Http::fake([
            '*eitaayar.ir/api/app/sendMessage' => Http::response([
                'ok' => true,
                'result' => 'success',
            ], 200),
        ]);
    }

    private function initData(int $userId = 279058397, bool $permission = true, ?int $timestamp = null): string
    {
        $values = [
            'auth_date' => (string) ($timestamp ?? time()),
            'chat_type' => 'private',
            'user' => json_encode([
                'id' => $userId,
                'first_name' => 'Test',
                'allows_write_to_pm' => $permission,
            ], JSON_THROW_ON_ERROR),
        ];

        ksort($values, SORT_STRING);
        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        $secret = hash_hmac('sha256', 'eitaa-test-token', 'WebAppData', true);
        $values['hash'] = hash_hmac('sha256', implode("\n", $pairs), $secret);

        return http_build_query($values);
    }

    public function test_user_connects_with_verified_initdata_and_one_time_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $code = $this->postJson('/api/v1/channels/eitaa/connect')
            ->assertOk()->json('pairing_code');

        $this->assertSame(16, strlen($code));

        $payload = ['pairing_code' => $code, 'init_data' => $this->initData()];
        $this->postJson('/api/v1/channels/eitaa/complete', $payload)->assertOk();

        $this->assertDatabaseHas('connections', [
            'user_id' => $user->id,
            'provider' => 'eitaa',
            'external_id' => '279058397',
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/channels/eitaa/complete', $payload)->assertStatus(422);
        $this->assertDatabaseCount('connections', 1);
        $this->getJson('/api/v1/channels/eitaa/connection')->assertOk()->assertJson(['connected' => true]);
    }

    public function test_tampered_and_expired_initdata_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $code = $this->postJson('/api/v1/channels/eitaa/connect')->json('pairing_code');

        $tampered = str_replace('279058397', '279058398', urldecode($this->initData()));
        $this->postJson('/api/v1/channels/eitaa/complete', [
            'pairing_code' => $code,
            'init_data' => $tampered,
        ])->assertStatus(422);

        $this->postJson('/api/v1/channels/eitaa/complete', [
            'pairing_code' => $code,
            'init_data' => $this->initData(timestamp: time() - 700),
        ])->assertStatus(422);

        $this->assertDatabaseCount('connections', 0);
    }

    public function test_permission_is_required(): void
    {
        $user = User::factory()->create();
        $code = app(EitaaService::class)->createPairingCode($user);

        $this->postJson('/api/v1/channels/eitaa/complete', [
            'pairing_code' => $code,
            'init_data' => $this->initData(permission: false),
        ])->assertStatus(422);

        $this->assertDatabaseCount('connections', 0);
    }

    public function test_one_eitaa_account_cannot_link_to_two_hub_users(): void
    {
        $owner = User::factory()->create();
        $another = User::factory()->create();
        $eitaa = app(EitaaService::class);
        $eitaa->completePairing($eitaa->createPairingCode($owner), '279058397');

        $this->postJson('/api/v1/channels/eitaa/complete', [
            'pairing_code' => $eitaa->createPairingCode($another),
            'init_data' => $this->initData(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('connections', 1);
    }

    public function test_budget_is_tracked_only_for_accepted_messages(): void
    {
        config()->set('services.eitaa.message_budget', 1);
        $eitaa = app(EitaaService::class);

        $response = $eitaa->sendMessage('279058397', 'Testing');
        $this->assertTrue($response['ok']);
        $this->assertSame(1, $eitaa->sentCount());

        try {
            $eitaa->sendMessage('279058397', 'Over budget');
            $this->fail('Expected local budget restriction.');
        } catch (EitaaApiException) {
            $this->assertSame(1, $eitaa->sentCount());
        }

        Http::assertSentCount(1);
    }

    public function test_priority_queue_is_used_for_eitaa_delivery(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $user->id,
            'name' => 'CRM',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $connectionId = DB::table('connections')->insertGetId([
            'user_id' => $user->id,
            'provider' => 'eitaa',
            'external_id' => '279058397',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $notificationId = DB::table('notifications')->insertGetId([
            'user_id' => $user->id,
            'project_id' => $projectId,
            'title' => 'CRM reminder',
            'priority' => 1,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(EitaaService::class)->queueDelivery($notificationId, $connectionId);

        Bus::assertDispatched(SendEitaaNotification::class, fn (SendEitaaNotification $job) => $job->queue === 'critical');
    }

    public function test_connect_api_requires_authentication(): void
    {
        $this->postJson('/api/v1/channels/eitaa/connect')->assertUnauthorized();
    }
}
