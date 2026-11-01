<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramInternalClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TelegramWebhookController extends Controller
{
    // This endpoint is deployed on Germany; Telegram sends webhook events here.
    public function __invoke(Request $request, TelegramInternalClient $internal): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        $provided = $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (config('services.telegram.role') !== 'worker'
            || strlen($secret) < 32 || !hash_equals($secret, $provided)) {
            return response()->json(['ok' => false], 401);
        }

        if (strlen($request->getContent()) > 65536) {
            return response()->json(['ok' => false], 413);
        }

        $payload = $request->json()->all();
        if (!is_array($payload) || !is_int($payload['update_id'] ?? null)) {
            return response()->json(['ok' => true]); // Ignore irrelevant/malformed updates.
        }

        try {
            $response = $internal->post('core', $payload);
        } catch (Throwable) {
            // Telegram retries this update on non-2xx; do not acknowledge lost events.
            return response()->json(['ok' => false], 503);
        }

        if (!$response->successful()) {
            return response()->json(['ok' => false], 503);
        }

        return response()->json(['ok' => true]);
    }
}
