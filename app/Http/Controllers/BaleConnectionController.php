<?php

namespace App\Http\Controllers;

use App\Services\BaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BaleConnectionController extends Controller
{
    public function show(Request $request, BaleService $bale): JsonResponse
    {
        $connection = $bale->connectionFor($request->user());

        return response()->json([
            'connected' => $connection?->status === 'active',
            'status' => $connection?->status,
        ]);
    }

    public function connect(Request $request, BaleService $bale): JsonResponse
    {
        return response()->json(['url' => $bale->connectionUrl($request->user()), 'expires_in' => 600]);
    }

    public function disconnect(Request $request, BaleService $bale): JsonResponse
    {
        $bale->disconnect($request->user());

        return response()->json(['connected' => false]);
    }
}
