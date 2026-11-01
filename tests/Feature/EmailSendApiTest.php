<?php

namespace Tests\Feature;

use App\Jobs\SendEmailNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmailSendApiTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $owner->id, 'name' => 'CRM', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $accountId = DB::table('connections')->insertGetId([
            'user_id' => $owner->id, 'provider' => 'gmail',
            'external_id' => 'owner@example.com',
            'credentials' => Crypt::encryptString(json_encode(['refresh_token' => 'fake-test-token'])),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('project_email_accounts')->insert([
            'project_id' => $projectId, 'connection_id' => $accountId,
        ]);

        $token = 'nh_'.str_repeat('T', 48);
        DB::table('api_keys')->insert([
            'project_id' => $projectId, 'name' => 'CRM API',
            'token_hash' => hash('sha256', $token),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $projectId, $accountId, $token];
    }

    public function test_send_is_queued_once_with_same_idempotency_key(): void
    {
        Bus::fake();
        [$owner, $projectId, $accountId, $token] = $this->fixture();

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => str_repeat('i', 32),
        ];
        $payload = [
            'account_id' => $accountId,
            'to' => ['recipient@example.com'],
            'subject' => 'Reminder',
            'text' => 'Test text body',
        ];

        $first = $this->postJson('/api/v1/emails', $payload, $headers)->assertStatus(202);
        $id = $first->json('notification_id');
        $this->postJson('/api/v1/emails', $payload, $headers)
            ->assertOk()->assertJson(['notification_id' => $id, 'duplicate' => true]);

        $this->assertDatabaseCount('email_messages', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertDatabaseHas('notifications', [
            'id' => $id, 'project_id' => $projectId, 'body' => null,
        ]);
    }

    public function test_project_cannot_use_email_not_explicitly_allowed(): void
    {
        Bus::fake();
        [$owner, $projectId, $accountId, $token] = $this->fixture();

        DB::table('project_email_accounts')->delete();

        $this->postJson('/api/v1/emails', [
            'account_id' => $accountId, 'to' => ['someone@example.com'],
            'subject' => 'Unauthorized', 'text' => 'Test body',
        ], [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => str_repeat('k', 32),
        ])->assertStatus(422);

        $this->assertDatabaseCount('email_messages', 0);
    }

    public function test_idempotency_key_cannot_be_reused_for_different_message(): void
    {
        Bus::fake();
        [$owner, $projectId, $accountId, $token] = $this->fixture();
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => str_repeat('k', 32),
        ];

        $this->postJson('/api/v1/emails', [
            'account_id' => $accountId, 'to' => ['someone@example.com'],
            'subject' => 'First', 'text' => 'Body',
        ], $headers)->assertStatus(202);

        $this->postJson('/api/v1/emails', [
            'account_id' => $accountId, 'to' => ['someone@example.com'],
            'subject' => 'Changed', 'text' => 'Body',
        ], $headers)->assertStatus(422);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_owner_can_generate_and_revoke_key_without_exposing_hash(): void
    {
        [$owner, $projectId] = $this->fixture();
        $this->actingAs($owner);

        $res = $this->postJson('/api/v1/email/projects/'.$projectId.'/keys', [
            'name' => 'New key',
        ])->assertCreated();

        $token = $res->json('token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('api_keys', ['token_hash' => hash('sha256', $token)]);

        $keyId = $res->json('id');
        $this->deleteJson('/api/v1/email/projects/'.$projectId.'/keys/'.$keyId)
            ->assertOk();

        $this->assertNotNull(DB::table('api_keys')->where('id', $keyId)->value('revoked_at'));
    }
}
