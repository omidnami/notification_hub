<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailProjectKeysController extends Controller
{
    public function index(Request $request, int $project): JsonResponse
    {
        if (!$this->ownsProject($request, $project)) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $keys = DB::table('api_keys')->where('project_id', $project)
            ->get(['id', 'name', 'expires_at', 'revoked_at', 'created_at']);

        return response()->json(['keys' => $keys]);
    }

    public function store(Request $request, int $project): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        if (!$this->ownsProject($request, $project)) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $token = 'nh_'.Str::random(48);
        $id = DB::transaction(function () use ($project, $input, $token): ?int {
            DB::table('projects')->where('id', $project)->lockForUpdate()->first(['id']);
            $count = DB::table('api_keys')->where('project_id', $project)
                ->whereNull('revoked_at')
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->count();

            if ($count >= 5) {
                return null;
            }

            return DB::table('api_keys')->insertGetId([
                'project_id' => $project,
                'name' => $input['name'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => $input['expires_at'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        if ($id === null) {
            return response()->json(['message' => 'Maximum five active keys per project.'], 422);
        }

        return response()->json(['id' => $id, 'token' => $token], 201);
    }

    public function destroy(Request $request, int $project, int $key): JsonResponse
    {
        if (!$this->ownsProject($request, $project)) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        DB::table('api_keys')->where('project_id', $project)->where('id', $key)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return response()->json(['revoked' => true]);
    }

    private function ownsProject(Request $request, int $project): bool
    {
        return DB::table('projects')->where('id', $project)
            ->where('user_id', $request->user()->id)->where('status', 'active')->exists();
    }
}
