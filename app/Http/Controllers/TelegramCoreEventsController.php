<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramCoreEventsController extends Controller
{
    // HMAC and role enforcement is done by VerifyTelegramInternal:core.
    public function __invoke(Request $request, TelegramService $telegram): JsonResponse
    {
        $payload = $request->json()->all();
        if (!is_array($payload) || !is_int($payload['update_id'] ?? null)) {
            return response()->json(['ok' => true]);
        }

        $telegram->handleUpdate($payload);

        return response()->json(['ok' => true]);
    }
}
