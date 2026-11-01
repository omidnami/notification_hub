<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateEmailProject
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!is_string($token) || strlen($token) < 32 || strlen($token) > 256) {
            abort(401);
        }

        $project = DB::table('api_keys')
            ->join('projects', 'projects.id', '=', 'api_keys.project_id')
            ->where('api_keys.token_hash', hash('sha256', $token))
            ->whereNull('api_keys.revoked_at')
            ->where(function ($query) {
                $query->whereNull('api_keys.expires_at')->orWhere('api_keys.expires_at', '>', now());
            })
            ->where('projects.status', 'active')
            ->select('projects.id', 'projects.user_id')
            ->first();

        if (!$project) {
            abort(401);
        }

        $request->attributes->set('email_project', $project);

        return $next($request);
    }
}
