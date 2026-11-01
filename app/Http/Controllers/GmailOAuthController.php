<?php

namespace App\Http\Controllers;

use App\Services\Email\EmailAccountService;
use App\Services\Email\GatewayClient;
use App\Services\Email\MailGatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class GmailOAuthController extends Controller
{
    public function begin(Request $request): JsonResponse
    {
        $clientId = (string) config('services.email_gateway.google_client_id');
        $redirect = (string) config('services.email_gateway.google_redirect_uri');

        if ($clientId === '' || !str_starts_with($redirect, 'https://')) {
            return response()->json(['message' => 'Google OAuth is not configured.'], 503);
        }

        $state = Str::random(48);
        Cache::put('email:google:state:'.hash('sha256', $state), (int) $request->user()->id, now()->addMinutes(10));

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'scope' => 'openid email https://www.googleapis.com/auth/gmail.send',
            'state' => $state,
        ]);

        return response()->json(['url' => $url]);
    }

    public function callback(
        Request $request,
        GatewayClient $gateway,
        EmailAccountService $accounts,
    ): JsonResponse {
        $input = $request->validate([
            'state' => ['required', 'string', 'size:48'],
            'code' => ['required', 'string', 'max:4096'],
        ]);

        $stateKey = 'email:google:state:'.hash('sha256', $input['state']);
        // State must also belong to this exact browser session's Hub account.
        $userId = Cache::get($stateKey);

        if (!is_int($userId) || $userId < 1 || $userId !== (int) $request->user()->id) {
            return response()->json(['message' => 'Invalid OAuth account or state.'], 422);
        }

        Cache::forget($stateKey);

        try {
            $tokens = $gateway->call('gmail.exchange', ['code' => $input['code']]);
            $identity = $gateway->call('gmail.user', ['access_token' => $tokens['access_token']]);
            $id = $accounts->save($userId, 'gmail', $identity['email'], [
                'refresh_token' => $tokens['refresh_token'],
                'google_sub' => $identity['sub'],
            ]);
            return response()->json(['connected' => true, 'account_id' => $id, 'email' => $identity['email']]);
        } catch (Throwable) {
            return response()->json(['message' => 'Gmail connection failed. Please reconnect.'], 422);
        }
    }
}
