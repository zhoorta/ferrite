<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Support\AppConnect;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectController extends Controller
{
    /**
     * Without a token: lets an app check that an address is a Ferrite server before it opens the browser.
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'app' => 'ferrite',
            'api' => 1,
            'name' => config('app.name'),
            'connect' => ! Demo::enabled(),
        ]);
    }

    /**
     * The last step of the browser approval: the one-time code and the PKCE verifier for a token (shown once).
     */
    public function token(Request $request, AppConnect $connect): JsonResponse
    {
        abort_if(Demo::enabled(), 404);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'verifier' => ['required', 'string', 'min:43', 'max:128'],
        ]);

        $result = $connect->redeem($data['code'], $data['verifier']);

        abort_if($result === null, 400, 'The code is invalid, used or expired.');

        /** @var ApiToken $token */
        [$token, $plain] = $result;

        return response()->json([
            'token' => $plain,
            'name' => $token->rootFolder()->name ?? 'Whole drive',
            'access' => implode('+', $token->abilities ?? []),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ]);
    }
}
