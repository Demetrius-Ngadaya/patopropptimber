<?php

namespace App\Platform\Http\Middleware;

use App\Platform\Support\BusinessManager;
use App\Platform\Support\SubscriptionState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform mode: find the business a request is for and switch to its data.
 *
 * The website proxy and the mobile app send the X-Business header (the slug,
 * as in patopro.shezoni.com/<slug>). Payment gateway callbacks and links in
 * SMS can carry ?business=<slug> instead. Requests for the platform itself
 * (sign-up, packages, the sign-in directory) and the health check need no business.
 *
 * Single mode: does nothing.
 */
class IdentifyBusiness
{
    /** Paths (inside /api/v1) that work without a business. */
    private const CENTRAL = ['health', 'platform', 'platform/*'];

    public function __construct(private readonly BusinessManager $businesses) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! BusinessManager::platformMode()) {
            return $next($request);
        }

        $slug = trim((string) ($request->header('X-Business') ?: $request->query('business', '')));

        if ($slug === '') {
            if ($this->isCentral($request)) {
                $this->businesses->forget(); // the platform's own data only

                return $next($request);
            }

            return $this->refuse(__('Choose a business first.'), 'BUSINESS_REQUIRED', 400);
        }

        $business = $this->businesses->findBySlug($slug);
        if (! $business || $business->status !== 'ready') {
            return $this->refuse(__('No business was found at this address.'), 'BUSINESS_NOT_FOUND', 404);
        }

        $state = $business->state();
        if (! $state->canOpen()) {
            return $state === SubscriptionState::Suspended
                ? $this->refuse(__('This business has been suspended. Please contact SHEZONI support.'), 'BUSINESS_SUSPENDED', 403)
                : $this->refuse(__('This business has been archived. Pay the subscription to bring it back, or contact SHEZONI support.'), 'BUSINESS_ARCHIVED', 403);
        }

        $this->businesses->switchTo($business);

        $response = $next($request);
        $response->headers->set('X-Business-State', $state->value);

        return $response;
    }

    private function isCentral(Request $request): bool
    {
        foreach (self::CENTRAL as $pattern) {
            if ($request->is('api/v1/'.$pattern) || $request->is($pattern)) {
                return true;
            }
        }

        return $request->is('/') || $request->is('up');
    }

    private function refuse(string $message, string $code, int $status): Response
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}
