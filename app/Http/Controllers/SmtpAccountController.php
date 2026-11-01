<?php

namespace App\Http\Controllers;

use App\Services\Email\EmailAccountService;
use App\Services\Email\GatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class SmtpAccountController extends Controller
{
    public function store(Request $request, EmailAccountService $accounts, GatewayClient $gateway): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'in:465,587'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $email = strtolower($input['email']);
        $code = (string) random_int(100000, 999999);
        $credentials = [
            'host' => $input['host'], 'port' => $input['port'],
            'username' => $input['username'], 'password' => $input['password'],
            'verification_hash' => Hash::make($code),
            'verification_expires_at' => now()->addMinutes(10)->timestamp,
        ];

        try {
            $gateway->call('smtp.send', [
                'delivery_key' => Str::random(40),
                'account' => $credentials,
                'message' => [
                    'from' => $email, 'to' => [$email],
                    'subject' => 'Notification Hub – Email verification',
                    'text' => 'Verification code: '.$code,
                ],
            ]);
            $id = $accounts->save((int) $request->user()->id, 'smtp', $email, $credentials, 'pending');
        } catch (Throwable) {
            return response()->json(['message' => 'SMTP verification email could not be sent.'], 422);
        }

        return response()->json(['account_id' => $id, 'status' => 'pending'], 202);
    }

    public function verify(Request $request, int $id, EmailAccountService $accounts): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'digits:6']]);
        try {
            $account = $accounts->getOwned((int) $request->user()->id, $id);
            if ($account->provider !== 'smtp' || $account->status !== 'pending') {
                return response()->json(['message' => 'No pending verification.'], 422);
            }
            $credentials = $accounts->credentials($account);
            if (($credentials['verification_expires_at'] ?? 0) < time()
                || !Hash::check($input['code'], $credentials['verification_hash'] ?? '')) {
                return response()->json(['message' => 'Invalid or expired verification code.'], 422);
            }
            unset($credentials['verification_hash'], $credentials['verification_expires_at']);
            DB::table('connections')->where('id', $id)->where('status', 'pending')->update([
                'credentials' => Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)),
                'status' => 'active',
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            return response()->json(['message' => 'SMTP verification failed.'], 422);
        }

        return response()->json(['verified' => true]);
    }
}
