<?php

namespace App\Http\Middleware;

use App\Services\Core\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Roles listed in the "two_factor_required_roles" setting cannot use the
 * back office until they have turned on two-factor login.
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->hasTwoFactor() && TwoFactorService::requiredFor($user)) {
            return response()->json([
                'success' => false,
                'code' => 'TWO_FACTOR_SETUP_REQUIRED',
                'message' => __('Your role must use two-factor login. Set it up in your profile to continue.'),
            ], 403);
        }

        return $next($request);
    }
}
