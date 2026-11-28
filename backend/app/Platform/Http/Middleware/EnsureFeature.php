<?php

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware "feature:voice": the business's package must include it. */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (plan()->allows($feature)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => __('Your package (:plan) does not include this feature. Upgrade the package to use it.', ['plan' => plan()->name]),
            'code' => 'PLAN_FEATURE',
            'feature' => $feature,
        ], 403);
    }
}
