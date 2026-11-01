<?php

namespace App\Http\Controllers;

use App\Services\Push\PushDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PushApiController extends Controller
{
    public function store(Request $request, PushDispatchService $push): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key)) {
            return response()->json(['message' => 'Valid Idempotency-Key required.'], 422);
        }

        $input = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:1024'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'device_ids' => ['sometimes', 'array', 'min:1', 'max:20'],
            'device_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $icon = $input['icon'] ?? '/push-icon.svg';
        $url = $input['url'] ?? '/';
        if (!$this->localPath($icon) || !$this->localPath($url)) {
            return response()->json(['message' => 'Icon and target URL must be local paths.'], 422);
        }

        $project = $request->attributes->get('email_project'); // Existing bearer-key project authentication.
        $deviceIds = $input['device_ids'] ?? null;
        if ($deviceIds !== null) {
            sort($deviceIds, SORT_NUMERIC);
            $count = DB::table('push_subscriptions')
                ->where('user_id', $project->user_id)->where('status', 'active')
                ->whereIn('id', $deviceIds)->count();
            if ($count !== count($deviceIds)) {
                return response()->json(['message' => 'Invalid or inactive device selection.'], 422);
            }
        }

        if ($deviceIds === null && !DB::table('push_subscriptions')
            ->where('user_id', $project->user_id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'No active push devices for project owner.'], 422);
        }

        $hash = hash('sha256', json_encode([
            'title' => $input['title'], 'body' => $input['body'], 'icon' => $icon,
            'url' => $url, 'priority' => $input['priority'] ?? 4,
            'device_ids' => $deviceIds,
        ], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($project, $input, $hash, $url, $icon, $key, $push, $deviceIds): array {
            DB::table('projects')->where('id', $project->id)->lockForUpdate()->first(['id']);

            $existing = DB::table('notifications')
                ->where('project_id', $project->id)->where('idempotency_key', $key)->first();

            if ($existing) {
                $stored = DB::table('web_push_messages')
                    ->where('notification_id', $existing->id)->value('request_hash');
                return ['id' => (int) $existing->id, 'duplicate' => true, 'conflict' => $stored !== $hash];
            }

            $id = DB::table('notifications')->insertGetId([
                'user_id' => $project->user_id, 'project_id' => $project->id,
                'title' => $input['title'], 'body' => $input['body'],
                'priority' => $input['priority'] ?? 4, 'status' => 'pending',
                'idempotency_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('web_push_messages')->insert([
                'notification_id' => $id,
                'icon' => $icon, 'target_url' => $url, 'request_hash' => $hash,
            ]);

            $queued = $push->queueNotification((int) $id, $deviceIds);

            return ['id' => $id, 'duplicate' => false, 'conflict' => false, 'devices' => count($queued)];
        });

        if ($result['conflict']) {
            return response()->json(['message' => 'Idempotency key already used.'], 409);
        }

        return response()->json([
            'notification_id' => $result['id'],
            'queued_devices' => $result['devices'] ?? null,
            'duplicate' => $result['duplicate'],
        ], $result['duplicate'] ? 200 : 202);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('email_project');
        if (!DB::table('notifications')->where('id', $id)
            ->where('project_id', $project->id)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('web_push_messages')
                    ->whereColumn('web_push_messages.notification_id', 'notifications.id');
            })->exists()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $deliveries = DB::table('notification_deliveries')
            ->where('notification_id', $id)->where('channel', 'webpush')
            ->orderBy('id')->get(['id', 'push_subscription_id as device_id', 'status', 'attempts', 'sent_at', 'last_error']);

        return response()->json([
            'notification_id' => $id,
            'deliveries' => $deliveries,
            'final_device_delivery_confirmed' => false,
        ]);
    }

    private function localPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//')
            && !str_contains($path, '\\') && !str_contains($path, "\r")
            && !str_contains($path, "\n");
    }
}
