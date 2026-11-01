<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Email\EmailDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmailGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_cannot_send_from_unassigned_or_foreign_email_account(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $owner->id, 'name' => 'CRM', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignId = DB::table('connections')->insertGetId([
            'user_id' => $other->id, 'provider' => 'gmail', 'status' => 'active',
            'external_id' => 'other@example.com', 'credentials' => 'encrypted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertDatabaseCount('email_messages', 0);

        try {
            app(EmailDispatchService::class)->enqueue(
                (object) ['id' => $projectId, 'user_id' => $owner->id],
                ['account_id' => $foreignId, 'to' => ['recipient@example.com'], 'subject' => 'Test', 'text' => 'Hello'],
                str_repeat('a', 32),
            );
            $this->fail('Expected unauthorized sender.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('notifications', 0);
        }
    }

    public function test_api_requires_valid_project_key(): void
    {
        $this->postJson('/api/v1/emails', [
            'account_id' => 1, 'to' => ['to@example.com'],
            'subject' => 'Test', 'text' => 'Hello',
        ], ['Authorization' => 'Bearer '.str_repeat('t', 48), 'Idempotency-Key' => str_repeat('k', 32)])
            ->assertUnauthorized();
    }

    public function test_gateway_rejects_unsigned_request(): void
    {
        config()->set('services.email_gateway.enabled', true);
        config()->set('services.email_gateway.secret', str_repeat('x', 48));

        $this->postJson('/internal/mail-gateway', [
            'action' => 'smtp.send', 'payload' => [],
        ])->assertUnauthorized();
    }

    public function test_gateway_refuses_replayed_signature(): void
    {
        config()->set('services.email_gateway.enabled', true);
        config()->set('services.email_gateway.secret', str_repeat('x', 48));
        config()->set('cache.default', 'array');

        $body = '{"action":"invalid","payload":[]}';
        $timestamp = (string) time();
        $nonce = str_repeat('a', 40);
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$body, str_repeat('x', 48));
        $headers = [
            'X-NH-Time' => $timestamp,
            'X-NH-Nonce' => $nonce,
            'X-NH-Signature' => $signature,
            'Content-Type' => 'application/json',
        ];
        $this->call('POST', '/internal/mail-gateway', [], [], [], $this->transformHeadersToServerVars($headers), $body)
            ->assertStatus(422);
        $this->call('POST', '/internal/mail-gateway', [], [], [], $this->transformHeadersToServerVars($headers), $body)
            ->assertUnauthorized();
    }
}
