<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keeps demo visitors out of the pages that change the account itself (e-mail, password, 2FA, passkeys). */
class BlockInDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Demo::isDemoUser($request->user())) {
            return redirect()->route('appearance.edit')->with('status', __('Not available in the demo.'));
        }

        return $next($request);
    }
}
