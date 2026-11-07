<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Software Engineer only. Anyone else gets a plain 404, so the existence of
 * these routes is not revealed even to super admins.
 */
class EnsureDeveloper
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isDeveloper()) {
            abort(404);
        }

        return $next($request);
    }
}
