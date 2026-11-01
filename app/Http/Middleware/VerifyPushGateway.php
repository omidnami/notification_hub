<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyPushGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.webpush.gateway_secret');
        if (!config('services.webpush.gateway_enabled') || strlen($secret) < 32) {
            abort(404);
        }

        $timestamp = $request->header('X-NH-Time', '');
        $nonce = $request->header('X-NH-Nonce', '');
        $signature = $request->header('X-NH-Signature', '');

        if (strlen($request->getContent()) > 8192 || !ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > 90
            || !preg_match('/^[A-Za-z0-9]{40}$/', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            abort(401);
        }

        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$request->getContent(), $secret);
        if (!hash_equals($expected, $signature)
            || !Cache::add('webpush:nonce:'.hash('sha256', $nonce), 1, 180)) {
            abort(401);
        }

        return $next($request);
    }
}
