<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RootController extends Controller
{
    /**
     * Test the connection: what the token reaches and for how long.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        /** @var ApiToken $token */
        $token = $user->currentAccessToken();
        $folder = $token->rootFolder();

        return response()->json([
            'name' => $folder?->name ?? 'Whole drive',
            'id' => $folder?->id,
            'access' => $token->can('write') ? 'read+write' : 'read',
            'expires_at' => $token->expires_at?->toIso8601String(),
            'quota' => ['used' => $user->used_bytes, 'limit' => $user->quota_bytes],
        ]);
    }
}
