<?php

namespace App\Http\Controllers;

use App\Services\EitaaInitData;
use App\Services\EitaaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EitaaConnectionController extends Controller
{
    public function show(Request $request, EitaaService $eitaa): JsonResponse
    {
        $connection = $eitaa->connectionFor($request->user());

        return response()->json([
            'connected' => $connection?->status === 'active',
            'status' => $connection?->status,
        ]);
    }

    public function connect(Request $request, EitaaService $eitaa): JsonResponse
    {
        return response()->json([
            'pairing_code' => $eitaa->createPairingCode($request->user()),
            'app_url' => config('services.eitaa.app_url'),
            'expires_in' => 600,
        ]);
    }

    public function complete(Request $request, EitaaService $eitaa, EitaaInitData $validator): JsonResponse
    {
        $values = $request->validate([
            'pairing_code' => ['required', 'string', 'size:16', 'regex:/^[A-Za-z0-9]+$/'],
            'init_data' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $user = $validator->verifiedUser($values['init_data']);
            $eitaa->completePairing($values['pairing_code'], (string) $user['id']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => 'Invalid or expired Eitaa connection request.'], 422);
        }

        return response()->json(['connected' => true]);
    }

    public function disconnect(Request $request, EitaaService $eitaa): JsonResponse
    {
        $eitaa->disconnect($request->user());

        return response()->json(['connected' => false]);
    }
}
