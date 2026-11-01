<?php

namespace App\Http\Controllers;

use App\Services\Email\EmailDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmailApiController extends Controller
{
    public function store(Request $request, EmailDispatchService $service): JsonResponse
    {
        if (strlen($request->getContent()) > 10 * 1024 * 1024) {
            return response()->json(['message' => 'Request too large.'], 413);
        }

        $idempotencyKey = $request->header('Idempotency-Key', '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $idempotencyKey)) {
            return response()->json(['message' => 'Idempotency-Key header required (16-128 characters).'], 422);
        }

        $input = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'array', 'min:1', 'max:10'],
            'to.*' => ['required', 'email:rfc', 'max:255'],
            'cc' => ['sometimes', 'array', 'max:10'],
            'cc.*' => ['required', 'email:rfc', 'max:255'],
            'bcc' => ['sometimes', 'array', 'max:10'],
            'bcc.*' => ['required', 'email:rfc', 'max:255'],
            'reply_to' => ['nullable', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'text' => ['nullable', 'string', 'max:200000'],
            'html' => ['nullable', 'string', 'max:200000'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'attachments' => ['sometimes', 'array', 'max:3'],
            'attachments.*.filename' => ['required', 'string', 'max:128', 'not_regex:/[\r\n]/'],
            'attachments.*.content_type' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9.+-]+\/[A-Za-z0-9.+-]+$/'],
            'attachments.*.content_base64' => ['required', 'string', 'max:8500000'],
        ]);

        if (empty($input['text']) && empty($input['html'])) {
            return response()->json(['message' => 'Text or HTML content is required.'], 422);
        }

        try {
            $result = $service->enqueue($request->attributes->get('email_project'), $input, $idempotencyKey);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'notification_id' => $result['id'],
            'duplicate' => $result['duplicate'],
            'status' => 'queued',
        ], $result['duplicate'] ? 200 : 202);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('email_project');
        $notification = DB::table('notifications')
            ->join('notification_deliveries', 'notification_deliveries.notification_id', '=', 'notifications.id')
            ->where('notifications.id', $id)->where('notifications.project_id', $project->id)
            ->where('notification_deliveries.channel', 'email')
            ->select(
                'notifications.id',
                'notification_deliveries.status',
                'notification_deliveries.attempts',
                'notification_deliveries.sent_at as accepted_at',
                'notification_deliveries.last_error',
            )
            ->first();

        if (!$notification) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'notification_id' => $notification->id,
            'status' => $notification->status,
            'attempts' => $notification->attempts,
            'accepted_at' => $notification->accepted_at,
            'error' => $notification->last_error,
            'final_delivery_confirmed' => false,
        ]);
    }
}
