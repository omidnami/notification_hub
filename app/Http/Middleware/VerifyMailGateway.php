<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyMailGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.email_gateway.secret');
        if (!config('services.email_gateway.enabled') || strlen($secret) < 32) {
            abort(404);
        }

        $time = $request->header('X-NH-Time', '');
        $nonce = $request->header('X-NH-Nonce', '');
        $signature = $request->header('X-NH-Signature', '');

        if (!ctype_digit($time) || abs(time() - (int) $time) > 90
            || !preg_match('/^[A-Za-z0-9]{40}$/', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            abort(401);
        }

        $expected = hash_hmac('sha256', $time."\n".$nonce."\n".$request->getContent(), $secret);
        if (!hash_equals($expected, $signature)
            || !Cache::add('mail-gateway:nonce:'.hash('sha256', $nonce), 1, 180)) {
            abort(401);
        }

        return $next($request);
    }
}
