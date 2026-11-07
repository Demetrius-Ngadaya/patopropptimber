<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->isCustomer() || ! $user->customer) {
            return response()->json(['success' => false, 'message' => __('Please log in with a customer account.')], 403);
        }

        return $next($request);
    }
}
