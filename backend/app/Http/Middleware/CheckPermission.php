<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('permission:products.view')            (required)
 *        ->middleware('permission:sales.view|allocations.view_own')  (any of)
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        $keys = explode('|', $permissions);

        if (! $user || ! $user->isStaff() || ! $user->hasAnyPermission($keys)) {
            return response()->json([
                'success' => false,
                'message' => __('You do not have permission to perform this action.'),
                'code' => 'FORBIDDEN',
                'required' => $keys,
            ], 403);
        }

        return $next($request);
    }
}
