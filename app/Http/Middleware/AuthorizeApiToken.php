<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After `auth:sanctum`: the owner must be active, the token must have the ability the route needs
 * (`api.token:read`, `api.token:write`) and its folder must still be reachable. Every answer is
 * `no-store`, except file content, which sets its own caching.
 */
class AuthorizeApiToken
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        abort_unless($token instanceof ApiToken, 401);
        abort_if($user->isDisabled(), 403, 'This account is disabled.');
        abort_unless($token->can($ability), 403, 'This token cannot do that.');
        $token->rootFolder();

        $response = $next($request);

        if (! $response->headers->has('ETag')) {
            $response->headers->set('Cache-Control', 'no-store');
        }
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
