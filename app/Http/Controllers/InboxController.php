<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'project_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'integer', 'between:1,5'],
            'read' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $query = DB::table('notifications')
            ->where('user_id', $request->user()->id)->whereNull('deleted_at');
        if (isset($input['project_id'])) $query->where('project_id', $input['project_id']);
        if (isset($input['priority'])) $query->where('priority', $input['priority']);
        if (isset($input['read'])) {
            $input['read'] ? $query->whereNotNull('read_at') : $query->whereNull('read_at');
        }
        if (isset($input['from'])) $query->whereDate('created_at', '>=', $input['from']);
        if (isset($input['to'])) $query->whereDate('created_at', '<=', $input['to']);
        if (!empty($input['q'])) {
            $search = addcslashes($input['q'], '%_\\');
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhere('body', 'like', '%'.$search.'%');
            });
        }

        return response()->json($query->orderByDesc('id')->paginate($input['per_page'] ?? 20, [
            'id', 'project_id', 'title', 'body', 'priority', 'status',
            'read_at', 'scheduled_at', 'created_at',
        ]));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $row = $this->owned($request, $id);
        $deliveries = DB::table('notification_deliveries')
            ->where('notification_id', $row->id)->orderBy('id')
            ->get(['channel', 'status', 'attempts', 'last_error', 'sent_at']);
        return response()->json(['notification' => $row, 'deliveries' => $deliveries]);
    }

    public function read(Request $request, int $id, NotificationEvents $events): JsonResponse
    {
        $row = $this->owned($request, $id);
        $affected = DB::table('notifications')
            ->where('id', $row->id)->whereNull('read_at')->update([
                'read_at' => now(), 'updated_at' => now(),
            ]);
        if ($affected && $row->project_id) {
            $events->publish((int) $row->project_id, (int) $id, 'notification.read', ['id' => $id]);
        }
        return response()->json(['read' => true]);
    }

    public function destroy(Request $request, int $id, NotificationEvents $events): JsonResponse
    {
        $row = $this->owned($request, $id);
        DB::transaction(function () use ($row, $events): void {
            DB::table('notifications')->where('id', $row->id)->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
            if ($row->project_id) {
                $events->publish((int) $row->project_id, (int) $row->id,
                    'notification.deleted', ['id' => $row->id]);
            }
        });
        return response()->json(['deleted' => true]);
    }

    public function stream(Request $request): StreamedResponse
    {
        $input = $request->validate(['last_id' => ['nullable', 'integer', 'min:0']]);
        $userId = (int) $request->user()->id;
        // EventSource sends Last-Event-ID on reconnect, preserving events in transit.
        $lastEventId = (string) $request->header('Last-Event-ID', '');
        $cursor = ctype_digit($lastEventId)
            ? (int) $lastEventId
            : (isset($input['last_id'])
                ? (int) $input['last_id']
                : (int) DB::table('notifications')->where('user_id', $userId)
                    ->whereNull('deleted_at')->max('id'));

        return response()->stream(function () use ($cursor, $userId): void {
            $end = microtime(true) + 20;
            echo "retry: 3000\n\n";
            @ob_flush();
            flush();
            while (microtime(true) < $end && !connection_aborted()) {
                $rows = DB::table('notifications')
                    ->where('user_id', $userId)->whereNull('deleted_at')
                    ->where('id', '>', $cursor)->orderBy('id')->limit(25)
                    ->get(['id', 'project_id', 'title', 'priority', 'created_at']);

                foreach ($rows as $row) {
                    $cursor = max($cursor, (int) $row->id);
                    echo 'id: '.$row->id."\n";
                    echo "event: notification\n";
                    echo 'data: '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n\n";
                }
                echo ": heartbeat\n\n";
                @ob_flush();
                flush();
                usleep(1500000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function owned(Request $request, int $id): object
    {
        $row = DB::table('notifications')->where('user_id', $request->user()->id)
            ->where('id', $id)->whereNull('deleted_at')->first([
                'id', 'project_id', 'title', 'body', 'priority', 'status',
                'read_at', 'created_at',
            ]);
        abort_if(!$row, 404);
        return $row;
    }
}
