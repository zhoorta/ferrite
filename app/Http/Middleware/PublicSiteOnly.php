<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The docs and download pages exist only while the project page (FERRITE_LANDING) is on. */
class PublicSiteOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('ferrite.landing'), 404);

        return $next($request);
    }
}
