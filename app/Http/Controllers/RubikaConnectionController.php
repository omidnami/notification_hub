<?php

namespace App\Http\Controllers;

use App\Services\RubikaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RubikaConnectionController extends Controller
{
    public function show(Request $request, RubikaService $rubika): JsonResponse
    {
        $connection = $rubika->connectionFor($request->user());

        return response()->json([
            'connected' => $connection?->status === 'active',
            'status' => $connection?->status,
        ]);
    }

    public function connect(Request $request, RubikaService $rubika): JsonResponse
    {
        return response()->json([
            'url' => $rubika->connectionUrl($request->user()),
            'expires_in' => 600,
        ]);
    }

    public function disconnect(Request $request, RubikaService $rubika): JsonResponse
    {
        $rubika->disconnect($request->user());

        return response()->json(['connected' => false]);
    }
}
