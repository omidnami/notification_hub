<?php

namespace App\Http\Controllers;

use App\Services\RubikaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RubikaWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, RubikaService $rubika): JsonResponse
    {
        $configured = (string) config('services.rubika.webhook_secret');
        abort_if(strlen($configured) < 32 || !hash_equals($configured, $secret), 404);

        if (strlen($request->getContent()) > 65536) {
            return response()->json(['error' => 'Payload too large'], 413);
        }

        $payload = $request->validate(['update' => ['required', 'array']]);

        $rubika->handleWebhook($payload);

        return response()->json(['status' => 'OK']);
    }
}
