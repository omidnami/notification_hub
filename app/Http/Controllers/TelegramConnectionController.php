<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TelegramConnectionController extends Controller
{
    public function show(Request $request, TelegramService $telegram): JsonResponse
    {
        $connection = $telegram->connectionFor($request->user());

        return response()->json([
            'connected' => $connection?->status === 'active',
            'status' => $connection?->status,
        ]);
    }

    public function connect(Request $request, TelegramService $telegram): JsonResponse
    {
        try {
            $url = $telegram->connectionUrl($request->user());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Telegram channel is not configured.'], 503);
        }

        return response()->json(['url' => $url, 'expires_in' => 600]);
    }

    public function disconnect(Request $request, TelegramService $telegram): JsonResponse
    {
        $telegram->disconnect($request->user());

        return response()->json(['connected' => false]);
    }
}
