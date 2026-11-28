<?php

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only businesses (subscription ended and grace over) can still sign in,
 * look at everything, export and pay, but cannot sell, buy or change anything.
 * The mobile app keeps unsent sales on the phone and sends them after payment.
 */
class EnsureBusinessWritable
{
    /** Changes that stay allowed when read-only (inside /api/v1). */
    private const ALWAYS_ALLOWED = [
        'auth/*',                 // sign in and out, password, profile
        'platform/*',             // paying the subscription
        'webhooks/*',             // payment confirmations
        'admin/mobile/devices*',  // phones registering for notifications
        'admin/notifications*',   // marking notifications read
        'admin/*/export*',
        'admin/reports/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || business()->canWrite()) {
            return $next($request);
        }

        foreach (self::ALWAYS_ALLOWED as $pattern) {
            if ($request->is('api/v1/'.$pattern)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => __('Your PatoPro package has ended. You can still view and export your records. Pay the subscription to continue selling.'),
            'code' => 'SUBSCRIPTION_READ_ONLY',
            'business' => business()->toArray(),
        ], 402);
    }
}
