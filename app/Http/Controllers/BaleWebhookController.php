<?php

namespace App\Http\Controllers;

use App\Services\BaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BaleWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, BaleService $bale): JsonResponse
    {
        $configured = (string) config('services.bale.webhook_secret');
        abort_if(strlen($configured) < 32 || !hash_equals($configured, $secret), 404);

        $update = $request->validate([
            'update_id' => ['required', 'integer'],
            'message' => ['sometimes', 'array'],
        ]);
        try {
            $bale->handleWebhook($update);
        } catch (\InvalidArgumentException $e) {
            // A claimed chat or expired account is rejected without webhook retries.
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => true]);
    }
}
