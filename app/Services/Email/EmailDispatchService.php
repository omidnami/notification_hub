<?php

namespace App\Services\Email;

use App\Jobs\SendEmailNotification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmailDispatchService
{
    public function enqueue(object $project, array $input, string $idempotencyKey): array
    {
        $connection = DB::table('connections')
            ->join('project_email_accounts', 'project_email_accounts.connection_id', '=', 'connections.id')
            ->where('project_email_accounts.project_id', $project->id)
            ->where('connections.id', $input['account_id'])
            ->where('connections.user_id', $project->user_id)
            ->whereIn('connections.provider', ['gmail', 'smtp'])
            ->where('connections.status', 'active')
            ->select('connections.id', 'connections.external_id')
            ->first();

        if (!$connection) {
            throw new InvalidArgumentException('Sender is not permitted for this project.');
        }

        $attachments = $input['attachments'] ?? [];
        $totalBytes = 0;
        foreach ($attachments as $attachment) {
            $decoded = base64_decode($attachment['content_base64'], true);
            if ($decoded === false) {
                throw new InvalidArgumentException('Malformed attachment.');
            }
            $totalBytes += strlen($decoded);
        }
        if ($totalBytes > 6 * 1024 * 1024) {
            throw new InvalidArgumentException('Attachments exceed the 6MB limit.');
        }

        $message = [
            'from' => $connection->external_id,
            'to' => $input['to'],
            'cc' => $input['cc'] ?? [],
            'bcc' => $input['bcc'] ?? [],
            'reply_to' => $input['reply_to'] ?? null,
            'subject' => $input['subject'],
            'text' => $input['text'] ?? null,
            'html' => $input['html'] ?? null,
            'attachments' => $attachments,
        ];

        $requestHash = hash('sha256', json_encode([
            'account_id' => $input['account_id'],
            'message' => $message,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($project, $message, $input, $idempotencyKey, $requestHash): array {
            // Serialize per-project creation and enforce idempotency.
            DB::table('projects')->where('id', $project->id)->lockForUpdate()->first(['id']);
            $existing = DB::table('notifications')
                ->where('project_id', $project->id)
                ->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                $prior = DB::table('email_messages')->where('notification_id', $existing->id)->first();
                if (!$prior || !hash_equals($prior->request_hash, $requestHash)) {
                    throw new InvalidArgumentException('Idempotency key already used with different content.');
                }
                return ['id' => (int) $existing->id, 'duplicate' => true];
            }

            $id = DB::table('notifications')->insertGetId([
                'user_id' => $project->user_id, 'project_id' => $project->id,
                'title' => $message['subject'], 'body' => null, 'priority' => $input['priority'] ?? 4,
                'status' => 'pending', 'idempotency_key' => $idempotencyKey,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('email_messages')->insert([
                'notification_id' => $id,
                'connection_id' => $input['account_id'],
                'payload' => Crypt::encryptString(json_encode($message, JSON_THROW_ON_ERROR)),
                'request_hash' => $requestHash, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $deliveryId = DB::table('notification_deliveries')->insertGetId([
                'notification_id' => $id, 'connection_id' => $input['account_id'],
                'channel' => 'email', 'status' => 'pending', 'attempts' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $priority = max(1, min(5, (int) ($input['priority'] ?? 4)));
            $queue = [1 => 'critical', 2 => 'urgent', 3 => 'high', 4 => 'normal', 5 => 'low'][$priority];

            DB::afterCommit(fn () => SendEmailNotification::dispatch((int) $deliveryId)->onQueue($queue));

            return ['id' => $id, 'duplicate' => false];
        });
    }
}
