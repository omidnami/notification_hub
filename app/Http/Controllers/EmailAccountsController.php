<?php

namespace App\Http\Controllers;

use App\Services\Email\EmailAccountService;
use App\Services\Email\GatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class EmailAccountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = DB::table('connections')
            ->where('user_id', $request->user()->id)
            ->whereIn('provider', ['gmail', 'smtp'])
            ->orderBy('id')
            ->get(['id', 'provider', 'external_id as email', 'status']);

        return response()->json(['accounts' => $accounts]);
    }

    public function allowProject(Request $request, int $id, EmailAccountService $accounts): JsonResponse
    {
        $input = $request->validate(['project_id' => ['required', 'integer']]);

        try {
            $account = $accounts->getOwned((int) $request->user()->id, $id);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        if ($account->status !== 'active'
            || !DB::table('projects')->where('id', $input['project_id'])
                ->where('user_id', $request->user()->id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'Account or project is not active.'], 422);
        }

        DB::table('project_email_accounts')->insertOrIgnore([
            'project_id' => $input['project_id'], 'connection_id' => $id,
        ]);

        return response()->json(['allowed' => true]);
    }

    public function disallowProject(Request $request, int $id, EmailAccountService $accounts): JsonResponse
    {
        $input = $request->validate(['project_id' => ['required', 'integer']]);

        try {
            $accounts->getOwned((int) $request->user()->id, $id);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        DB::table('project_email_accounts')
            ->where('connection_id', $id)
            ->whereIn('project_id', DB::table('projects')->where('user_id', $request->user()->id)->select('id'))
            ->where('project_id', $input['project_id'])->delete();

        return response()->json(['allowed' => false]);
    }

    public function disconnect(
        Request $request,
        int $id,
        EmailAccountService $accounts,
        GatewayClient $gateway,
    ): JsonResponse {
        try {
            $account = $accounts->getOwned((int) $request->user()->id, $id);
            $revoked = true;

            if ($account->provider === 'gmail') {
                try {
                    $credentials = $accounts->credentials($account);
                    $gateway->call('gmail.revoke', ['refresh_token' => $credentials['refresh_token']]);
                } catch (Throwable) {
                    $revoked = false;
                }
            }

            DB::transaction(function () use ($id, $revoked): void {
                DB::table('project_email_accounts')->where('connection_id', $id)->delete();
                DB::table('connections')->where('id', $id)->update([
                    'status' => 'disabled',
                    // Keep token for retrying provider revocation; it must never be used for sending.
                    'updated_at' => now(),
                ]);
            });

            return response()->json([
                'connected' => false,
                'provider_revoked' => $revoked,
                'message' => $revoked ? null : 'Provider revocation failed. Revoke access from your Google account.',
            ]);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Account not found.'], 404);
        }
    }
}
