<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationCore;
use App\Services\Notifications\NotificationEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NotificationApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $project = $request->attributes->get('email_project');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'integer', 'between:1,5'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'read' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $query = DB::table('notifications')
            ->where('project_id', $project->id)->whereNull('deleted_at');

        if (!empty($filters['q'])) {
            $query->where(function ($q) use ($filters): void {
                $search = addcslashes($filters['q'], '%_\\');
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhere('body', 'like', '%'.$search.'%');
            });
        }
        if (isset($filters['priority'])) $query->where('priority', $filters['priority']);
        if (isset($filters['from'])) $query->whereDate('created_at', '>=', $filters['from']);
        if (isset($filters['to'])) $query->whereDate('created_at', '<=', $filters['to']);
        if (isset($filters['read'])) {
            $filters['read'] ? $query->whereNotNull('read_at') : $query->whereNull('read_at');
        }

        return response()->json($query->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20, $this->columns()));
    }

    public function store(Request $request, NotificationCore $core): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key)) {
            return response()->json(['message' => 'Idempotency-Key is required (16-128 characters).'], 422);
        }

        $input = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'channels' => ['sometimes', 'array', 'min:1', 'max:8'],
            'channels.*' => ['required', 'string', 'distinct',
                'in:in_app,bale,rubika,eitaa,webpush,sms,voice_otp'],
            'sms' => ['nullable', 'array'],
            'sms.type' => ['nullable', 'string', 'in:text,pattern'],
            'sms.to' => ['nullable', 'string', 'max:16'],
            'sms.message' => ['nullable', 'string', 'max:1000'],
            'sms.code' => ['nullable', 'string', 'max:100'],
            'sms.params' => ['nullable', 'array', 'max:10'],
            'sms.params.*' => ['string', 'max:200'],
            'sms.consent' => ['nullable', 'boolean'],
            'voice_otp' => ['nullable', 'array'],
            'voice_otp.to' => ['nullable', 'string', 'max:16'],
            'voice_otp.code' => ['nullable', 'string', 'max:8'],
            'voice_otp.consent' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $core->create($request->attributes->get('email_project'), $input, $key);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['conflict']) {
            return response()->json(['message' => 'Idempotency key used for a different request.'], 409);
        }

        return response()->json([
            'id' => $result['id'],
            'duplicate' => $result['duplicate'],
            'status' => 'stored',
        ], $result['duplicate'] ? 200 : 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $row = $this->ownedNotification($request, $id);
        $deliveries = DB::table('notification_deliveries')
            ->where('notification_id', $id)->orderBy('id')
            ->get(['id', 'channel', 'status', 'attempts', 'last_error', 'sent_at', 'created_at']);
        return response()->json(['notification' => $row, 'deliveries' => $deliveries]);
    }

    public function update(Request $request, int $id, NotificationEvents $events): JsonResponse
    {
        $row = $this->ownedNotification($request, $id);
        $fields = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
        ]);

        if (!$fields) {
            return response()->json(['message' => 'No editable fields supplied.'], 422);
        }

        DB::transaction(function () use ($row, $fields, $events): void {
            DB::table('notifications')->where('id', $row->id)
                ->whereNull('deleted_at')->update($fields + ['updated_at' => now()]);

            $events->publish((int) $row->project_id, (int) $row->id, 'notification.updated', [
                'id' => $row->id, 'fields' => array_keys($fields),
            ]);
        });

        return response()->json(['updated' => true]);
    }

    public function destroy(Request $request, int $id, NotificationEvents $events): JsonResponse
    {
        $row = $this->ownedNotification($request, $id);
        DB::transaction(function () use ($row, $events): void {
            DB::table('notifications')->where('id', $row->id)->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            $events->publish((int) $row->project_id, (int) $row->id, 'notification.deleted', [
                'id' => $row->id,
            ]);
        });

        return response()->json(['deleted' => true, 'delivery_history_preserved' => true]);
    }

    public function read(Request $request, int $id, NotificationEvents $events): JsonResponse
    {
        $row = $this->ownedNotification($request, $id);
        $now = now();
        $affected = DB::table('notifications')->where('id', $row->id)->whereNull('read_at')
            ->whereNull('deleted_at')->update(['read_at' => $now, 'updated_at' => $now]);

        if ($affected) {
            $events->publish((int) $row->project_id, (int) $row->id, 'notification.read', [
                'id' => $row->id,
            ]);
        }

        return response()->json(['read' => true]);
    }

    private function ownedNotification(Request $request, int $id): object
    {
        $row = DB::table('notifications')
            ->where('project_id', $request->attributes->get('email_project')->id)
            ->whereNull('deleted_at')->where('id', $id)
            ->first($this->columns());
        abort_if(!$row, 404);
        return $row;
    }

    private function columns(): array
    {
        return ['id', 'project_id', 'title', 'body', 'priority',
            'status', 'scheduled_at', 'read_at', 'created_at', 'updated_at'];
    }
}
