<?php

namespace App\Http\Controllers;

use App\Services\Push\PushSendException;
use App\Services\Push\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushGatewayController extends Controller
{
    public function __invoke(Request $request, PushSender $sender): JsonResponse
    {
        $input = $request->validate([
            'subscription' => ['required', 'array'],
            'subscription.endpoint' => ['required', 'url:https', 'max:2048'],
            'subscription.keys' => ['required', 'array'],
            'subscription.keys.p256dh' => ['required', 'string', 'max:256'],
            'subscription.keys.auth' => ['required', 'string', 'max:128'],
            'notification' => ['required', 'array'],
            'notification.title' => ['required', 'string', 'max:120'],
            'notification.body' => ['nullable', 'string', 'max:500'],
            'notification.icon' => ['nullable', 'string', 'max:255'],
            'notification.url' => ['nullable', 'string', 'max:1024'],
            'notification.tag' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            app(\App\Services\Push\PushEndpointGuard::class)
                ->check($input['subscription']['endpoint']);
        } catch (\InvalidArgumentException) {
            return response()->json(['accepted' => false], 422);
        }

        try {
            $result = $sender->sendDirect($input['subscription'], $input['notification']);
            return response()->json(['accepted' => true, 'result' => $result]);
        } catch (PushSendException $exception) {
            $status = $exception->expired ? 410
                : ($exception->retryable ? 429 : ($exception->indeterminate ? 502 : 422));

            return response()->json(
                ['accepted' => false, 'expired' => $exception->expired, 'push_status' => $exception->httpStatus],
                $status,
                $exception->retryAfter > 0 ? ['Retry-After' => (string) $exception->retryAfter] : [],
            );
        }
    }
}
