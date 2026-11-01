<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramNotification;
use App\Jobs\SendTelegramWelcome;
use App\Models\User;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        config()->set('services.telegram.role', 'core');
        config()->set('services.telegram.username', 'Notification_Hub_Bot');
        config()->set('services.telegram.gateway_secret', str_repeat('s', 48));
        config()->set('services.telegram.gateway_url', 'https://germany.example');
        config()->set('services.telegram.core_url', 'https://iran.example');
        config()->set('services.telegram.webhook_secret', str_repeat('h', 40));
        config()->set('services.telegram.token', '123456:'.str_repeat('T', 35));
        Http::fake(['https://germany.example/internal/telegram/send' => Http::response([
            'ok' => true, 'result' => ['message_id' => 42],
        ], 200)]);
    }

    private function start(string $token, int $id = 1234567, string $type = 'private', ?int $from = null): array
    {
        return [
            'update_id' => 20,
            'message' => [
                'chat' => ['id' => $id, 'type' => $type],
                'from' => ['id' => $from ?? $id, 'is_bot' => false],
                'text' => '/start '.$token,
            ],
        ];
    }

    private function signedPost(string $path, array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $time = (string) time();
        $nonce = Str::random(40);
        $mac = hash_hmac('sha256', $time."\n".$nonce."\n".$path."\n".$body,
            config('services.telegram.gateway_secret'));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_NH_TELEGRAM_TIME' => $time,
            'HTTP_X_NH_TELEGRAM_NONCE' => $nonce,
            'HTTP_X_NH_TELEGRAM_SIGNATURE' => $mac,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_private_start_links_one_user_once_and_sends_one_welcome(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $url = $this->actingAs($user)
            ->postJson('/api/v1/channels/telegram/connect')->assertOk()->json('url');
        $this->assertStringStartsWith('https://t.me/Notification_Hub_Bot?start=', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        $payload = $this->start($parameters['start']);

        $this->signedPost('/internal/telegram/events', $payload)->assertOk();
        $this->assertDatabaseHas('connections', [
            'user_id' => $user->id, 'provider' => 'telegram',
            'external_id' => '1234567', 'status' => 'active',
        ]);

        // Fresh nonce, same already-consumed start token.
        $this->signedPost('/internal/telegram/events', $payload)->assertOk();
        $this->assertDatabaseCount('connections', 1);
        Bus::assertDispatchedTimes(SendTelegramWelcome::class, 1);
        $this->getJson('/api/v1/channels/telegram/connection')
            ->assertOk()->assertJson(['connected' => true]);
    }

    public function test_group_or_mismatched_sender_cannot_claim_account(): void
    {
        $user = User::factory()->create();
        $telegram = app(TelegramService::class);
        $url = $telegram->connectionUrl($user);
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

        $this->signedPost('/internal/telegram/events',
            $this->start($parameters['start'], -100123, 'supergroup'))->assertOk();
        $this->signedPost('/internal/telegram/events',
            $this->start($parameters['start'], 1234567, 'private', 99999))->assertOk();
        $this->assertDatabaseCount('connections', 0);

        $this->signedPost('/internal/telegram/events',
            $this->start($parameters['start'], 1234567))->assertOk();
        $this->assertDatabaseCount('connections', 1);
    }

    public function test_cannot_take_another_hub_users_telegram_chat(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        DB::table('connections')->insert([
            'user_id' => $owner->id, 'provider' => 'telegram',
            'external_id' => '1234567', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $url = app(TelegramService::class)->connectionUrl($other);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->signedPost('/internal/telegram/events', $this->start($params['start']))
            ->assertOk();
        $this->assertDatabaseCount('connections', 1);
        $this->assertDatabaseHas('connections', [
            'user_id' => $owner->id, 'external_id' => '1234567',
        ]);
    }

    public function test_disconnect_cancels_pending_delivery_and_old_pairing_token(): void
    {
        $user = User::factory()->create();
        $telegram = app(TelegramService::class);
        $url = $telegram->connectionUrl($user);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $connectionId = DB::table('connections')->insertGetId([
            'user_id' => $user->id, 'provider' => 'telegram',
            'external_id' => '1234567', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $notificationId = DB::table('notifications')->insertGetId([
            'user_id' => $user->id, 'title' => 'Test',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notification_deliveries')->insert([
            'notification_id' => $notificationId, 'connection_id' => $connectionId,
            'channel' => 'telegram', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->deleteJson('/api/v1/channels/telegram/connection')->assertOk();
        $this->assertDatabaseHas('connections', [
            'id' => $connectionId, 'external_id' => null, 'status' => 'disabled',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notificationId, 'status' => 'failed',
        ]);

        $this->signedPost('/internal/telegram/events', $this->start($params['start']))->assertOk();
        $this->assertDatabaseHas('connections', [
            'id' => $connectionId, 'external_id' => null, 'status' => 'disabled',
        ]);
    }

    public function test_member_kicked_event_blocks_connection(): void
    {
        $user = User::factory()->create();
        DB::table('connections')->insert([
            'user_id' => $user->id, 'provider' => 'telegram',
            'external_id' => '1234567', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->signedPost('/internal/telegram/events', [
            'update_id' => 22,
            'my_chat_member' => [
                'chat' => ['id' => 1234567, 'type' => 'private'],
                'new_chat_member' => ['status' => 'kicked'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('connections', ['user_id' => $user->id, 'status' => 'blocked']);
    }

    public function test_internal_events_require_role_and_valid_hmac(): void
    {
        $this->postJson('/internal/telegram/events', $this->start(str_repeat('a', 40)))
            ->assertUnauthorized();
        config()->set('services.telegram.role', 'worker');
        $this->signedPost('/internal/telegram/events', $this->start(str_repeat('a', 40)))
            ->assertNotFound();
    }

    public function test_telegram_webhook_requires_secret_token_and_forwards_signed_update(): void
    {
        config()->set('services.telegram.role', 'worker');

        $payload = $this->start(str_repeat('a', 40));
        $this->postJson('/hooks/telegram', $payload)->assertUnauthorized();
        Http::fake(['https://iran.example/internal/telegram/events' =>
            Http::response(['ok' => true], 200)]);

        $this->postJson('/hooks/telegram', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => str_repeat('h', 40),
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            $time = $request->header('X-NH-Telegram-Time')[0] ?? '';
            $nonce = $request->header('X-NH-Telegram-Nonce')[0] ?? '';
            $signature = $request->header('X-NH-Telegram-Signature')[0] ?? '';

            return $request->url() === 'https://iran.example/internal/telegram/events'
                && hash_equals(
                    hash_hmac('sha256', $time."\n".$nonce."\n".
                        '/internal/telegram/events'."\n".$request->body(),
                        config('services.telegram.gateway_secret')),
                    $signature,
                );
        });
    }

    public function test_telegram_delivery_is_owned_and_uses_priority_queue(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $connectionId = DB::table('connections')->insertGetId([
            'user_id' => $owner->id, 'provider' => 'telegram',
            'external_id' => '1234567', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $notificationId = DB::table('notifications')->insertGetId([
            'user_id' => $owner->id, 'title' => 'Server event',
            'priority' => 1, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $telegram = app(TelegramService::class);
        $id = $telegram->queueDelivery($notificationId, $connectionId);
        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $id, 'channel' => 'telegram', 'connection_id' => $connectionId,
        ]);
        Bus::assertDispatched(SendTelegramNotification::class,
            fn (SendTelegramNotification $job) => $job->queue === 'critical');

        $wrong = DB::table('notifications')->insertGetId([
            'user_id' => $other->id, 'title' => 'Foreign',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $telegram->queueDelivery($wrong, $connectionId);
    }

    public function test_worker_only_sends_authenticated_text_with_bot_api(): void
    {
        config()->set('services.telegram.role', 'worker');
        Http::fake(['https://api.telegram.org/bot*/sendMessage' =>
            Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200)]);

        $response = $this->signedPost('/internal/telegram/send', [
            'chat_id' => '1234567', 'text' => 'Testing Telegram',
        ])->assertOk();

        $this->assertSame(99, $response->json('result.message_id'));
        Http::assertSent(fn ($request) =>
            str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '1234567'
            && $request['text'] === 'Testing Telegram');
    }

    public function test_bot_failure_marks_blocked_and_does_not_retry(): void
    {
        $user = User::factory()->create();
        $connectionId = DB::table('connections')->insertGetId([
            'user_id' => $user->id, 'provider' => 'telegram',
            'external_id' => '1234567', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $notificationId = DB::table('notifications')->insertGetId([
            'user_id' => $user->id, 'title' => 'Alert',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $deliveryId = DB::table('notification_deliveries')->insertGetId([
            'notification_id' => $notificationId, 'connection_id' => $connectionId,
            'channel' => 'telegram', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $telegram = \Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()
            ->andThrow(new TelegramException('Forbidden', 403));

        (new SendTelegramNotification($deliveryId))->handle($telegram);
        $this->assertDatabaseHas('connections', ['id' => $connectionId, 'status' => 'blocked']);
        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $deliveryId, 'status' => 'failed',
        ]);
    }

    public function test_daily_quota_is_enforced_on_new_deliveries(): void
    {
        Bus::fake();
        config()->set('services.telegram.user_daily_limit', 1);
        config()->set('services.telegram.project_daily_limit', 1);
        $user = User::factory()->create();

        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $user->id,
            'name' => 'CRM',
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $connectionId = DB::table('connections')->insertGetId([
            'user_id' => $user->id, 'provider' => 'telegram',
            'external_id' => '1234567', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $first = DB::table('notifications')->insertGetId([
            'user_id' => $user->id, 'project_id' => $projectId,
            'title' => 'First', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $second = DB::table('notifications')->insertGetId([
            'user_id' => $user->id, 'project_id' => $projectId,
            'title' => 'Second', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $telegram = app(TelegramService::class);
        $firstDelivery = $telegram->queueDelivery($first, $connectionId);
        $this->assertSame($firstDelivery, $telegram->queueDelivery($first, $connectionId));

        $this->expectException(\InvalidArgumentException::class);
        $telegram->queueDelivery($second, $connectionId);
    }

    public function test_telegram_webhook_command_registers_official_secret_header(): void
    {
        config()->set('services.telegram.role', 'worker');
        config()->set('services.telegram.webhook_url', 'https://germany.example/hooks/telegram');
        Http::fake(['https://api.telegram.org/bot*/setWebhook' =>
            Http::response(['ok' => true, 'result' => true], 200)]);

        $this->artisan('telegram:webhook:set')->assertExitCode(0);
        Http::assertSent(fn ($request) =>
            str_ends_with($request->url(), '/setWebhook')
            && $request['secret_token'] === str_repeat('h', 40)
            && in_array('my_chat_member', $request['allowed_updates'], true));
    }

}
