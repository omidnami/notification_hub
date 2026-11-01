<?php

namespace App\Http\Controllers;

use App\Services\Webhooks\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OutgoingWebhookController extends Controller
{
    private const EVENTS = [
        'notification.created', 'notification.updated',
        'notification.read', 'notification.deleted', 'webhook.test',
    ];

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['project_id' => ['required', 'integer']]);
        $this->ownedProject($request, (int) $input['project_id']);

        $hooks = DB::table('webhooks')->where('project_id', $input['project_id'])
            ->orderBy('id')->get(['id', 'url', 'events', 'is_active', 'created_at'])
            ->map(function ($hook): array {
                $hook->events = json_decode($hook->events, true);
                return (array) $hook;
            });

        return response()->json(['webhooks' => $hooks]);
    }

    public function store(Request $request, UrlGuard $guard): JsonResponse
    {
        $input = $request->validate([
            'project_id' => ['required', 'integer'],
            'url' => ['required', 'url:https', 'max:2048'],
            'events' => ['required', 'array', 'min:1', 'max:5'],
            'events.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::EVENTS)],
        ]);

        $this->ownedProject($request, (int) $input['project_id']);
        try {
            $guard->check($input['url']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $secret = Str::random(64);
        try {
            $id = DB::transaction(function () use ($input, $secret): int {
                DB::table('projects')->where('id', $input['project_id'])->lockForUpdate()->first(['id']);
                if (DB::table('webhooks')->where('project_id', $input['project_id'])->count() >= 10) {
                    throw new InvalidArgumentException('Maximum ten webhooks per project.');
                }
                return DB::table('webhooks')->insertGetId([
                    'project_id' => $input['project_id'],
                    'url' => $input['url'],
                    'events' => json_encode($input['events'], JSON_THROW_ON_ERROR),
                    'secret' => Crypt::encryptString($secret),
                    'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $id, 'secret' => $secret], 201);
    }

    public function update(Request $request, int $id, UrlGuard $guard): JsonResponse
    {
        $hook = $this->ownedHook($request, $id);
        $input = $request->validate([
            'url' => ['sometimes', 'required', 'url:https', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1', 'max:5'],
            'events.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($input['url'])) {
            try {
                $guard->check($input['url']);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $changes = array_intersect_key($input, array_flip(['url', 'is_active']));
        if (isset($input['events'])) {
            $changes['events'] = json_encode($input['events'], JSON_THROW_ON_ERROR);
        }
        if ($changes) {
            $changes['updated_at'] = now();
            DB::table('webhooks')->where('id', $hook->id)->update($changes);
        }
        return response()->json(['updated' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->ownedHook($request, $id);
        DB::table('webhooks')->where('id', $id)->update([
            'is_active' => false, 'updated_at' => now(),
        ]);
        return response()->json(['disabled' => true]);
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $hook = $this->ownedHook($request, $id);
        if (!$hook->is_active) {
            return response()->json(['message' => 'Webhook disabled.'], 422);
        }

        // Send only to this endpoint even if other project hooks subscribe to tests.
        $eventId = (string) Str::uuid();
        $payload = [
            'id' => $eventId, 'type' => 'webhook.test',
            'occurred_at' => now()->toIso8601String(),
            'project_id' => (int) $hook->project_id,
            'notification_id' => null,
            'data' => ['message' => 'Notification Hub webhook test'],
        ];
        $deliveryId = DB::table('webhook_deliveries')->insertGetId([
            'webhook_id' => $id, 'notification_id' => null, 'event' => 'webhook.test',
            'event_key' => hash('sha256', $eventId),
            'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Jobs\SendOutgoingWebhook::dispatch((int) $deliveryId)->onQueue('normal')->afterCommit();
        return response()->json(['delivery_id' => $deliveryId, 'queued' => true], 202);
    }

    public function deliveries(Request $request, int $id): JsonResponse
    {
        $this->ownedHook($request, $id);
        $items = DB::table('webhook_deliveries')->where('webhook_id', $id)
            ->orderByDesc('id')->paginate(20, [
                'id', 'event', 'status', 'attempts', 'http_status', 'last_error',
                'sent_at', 'created_at',
            ]);
        return response()->json($items);
    }

    private function ownedProject(Request $request, int $id): object
    {
        $project = DB::table('projects')->where('id', $id)
            ->where('user_id', $request->user()->id)->first();
        abort_if(!$project, 404);
        return $project;
    }

    private function ownedHook(Request $request, int $id): object
    {
        $hook = DB::table('webhooks as w')->join('projects as p', 'p.id', '=', 'w.project_id')
            ->where('w.id', $id)->where('p.user_id', $request->user()->id)
            ->select('w.*')->first();
        abort_if(!$hook, 404);
        return $hook;
    }
}
