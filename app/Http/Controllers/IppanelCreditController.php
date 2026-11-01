<?php

namespace App\Http\Controllers;

use App\Services\Ippanel\IppanelAdapter;
use App\Services\Ippanel\IppanelException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IppanelCreditController extends Controller
{
    public function __invoke(Request $request, IppanelAdapter $adapter): JsonResponse
    {
        // Central IPPanel account balance is admin-only.
        $admin = DB::table('user_meta')->where('user_id', $request->user()->id)
            ->where('key', 'role')->where('value', json_encode('admin'))->exists();
        abort_unless($admin, 403);

        try {
            $credit = $adapter->credit();
        } catch (IppanelException $exception) {
            return response()->json(['message' => 'IPPanel balance unavailable.'], 503);
        }

        return response()->json([
            'credit' => $credit['credit'] ?? null,
            'gift' => $credit['gift'] ?? null,
            'updated_at' => $credit['updated_at'] ?? null,
        ]);
    }
}
