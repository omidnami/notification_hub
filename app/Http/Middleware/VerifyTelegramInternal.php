<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramInternal
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $secret = (string) config('services.telegram.gateway_secret');
        if (config('services.telegram.role') !== $role || strlen($secret) < 32) {
            abort(404);
        }

        $time = $request->header('X-NH-Telegram-Time', '');
        $nonce = $request->header('X-NH-Telegram-Nonce', '');
        $signature = $request->header('X-NH-Telegram-Signature', '');

        if (strlen($request->getContent()) > 65536
            || !ctype_digit($time) || abs(time() - (int) $time) > 90
            || !preg_match('/^[A-Za-z0-9]{40}$/', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            abort(401);
        }

        $path = '/'.$request->path();
        $expected = hash_hmac('sha256',
            $time."\n".$nonce."\n".$path."\n".$request->getContent(), $secret);

        if (!hash_equals($expected, $signature)
            || !Cache::add('telegram:internal:nonce:'.hash('sha256', $nonce), true, 180)) {
            abort(401);
        }

        return $next($request);
    }
}
