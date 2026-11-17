<?php

namespace App\Http\Middleware;

use App\Models\Core\LoginLog;
use App\Models\Core\PersonalAccessToken;
use App\Services\Core\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session after the admin-configured period of inactivity and blocks
 * deactivated / locked accounts immediately.
 */
class EnforceSessionTimeout
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Transient / test tokens (Sanctum::actingAs) are not stored and have no activity clock.
        if (! $user || ! $token instanceof PersonalAccessToken || ! $token->exists) {
            return $next($request);
        }

        if (! $user->is_active || $user->trashed()) {
            return $this->end($token, 'revoked', 'Your account has been deactivated. Contact the administrator.', 'ACCOUNT_DISABLED');
        }

        $minutes = match (true) {
            $user->isCustomer() => $this->settings->int('customer_session_timeout_minutes'),
            $token->isMobile() => $this->settings->int('mobile_idle_minutes'),
            default => $this->settings->int('session_timeout_minutes'),
        };

        $lastActivity = $token->last_activity_at ?? $token->created_at;
        if ($minutes > 0 && $lastActivity && $lastActivity->copy()->addMinutes($minutes)->isPast()) {
            return $this->end($token, 'expired', "Your session expired after {$minutes} minutes of inactivity. Please log in again.", 'SESSION_EXPIRED');
        }

        // Write at most once every 30 seconds to keep this cheap.
        $changes = [];
        if (! $token->last_activity_at || $token->last_activity_at->diffInSeconds(now(), true) >= 30) {
            $changes['last_activity_at'] = now();
        }

        // Someone who keeps working is never thrown out: the login is pushed forward
        // (a full period again) once less than half of it is left. Only inactivity ends it.
        $renewed = false;
        $window = $this->lifetimeHours($user, $token);
        if ($token->expires_at && $token->expires_at->lt(now()->addMinutes($window * 30))) {
            $changes['expires_at'] = now()->addHours($window);
            $renewed = true;
        }
        if ($changes) {
            $token->forceFill($changes)->saveQuietly();
        }

        $response = $next($request);
        $response->headers->set('X-Session-Timeout', (string) $minutes);
        if ($renewed) {
            // The website gateway moves its login cookie to the new time.
            $response->headers->set('X-Session-Expires', $token->expires_at->toIso8601String());
        }

        return $response;
    }

    /** How long a login lasts from its last use (same periods as at login). */
    private function lifetimeHours($user, PersonalAccessToken $token): int
    {
        return match (true) {
            $user->isCustomer() => 24 * 30,
            $token->isMobile() => 24 * max(1, $this->settings->int('mobile_session_days')),
            default => 24,
        };
    }

    private function end(PersonalAccessToken $token, string $reason, string $message, string $code): Response
    {
        LoginLog::query()->where('token_id', $token->id)->whereNull('logged_out_at')
            ->update(['logged_out_at' => now(), 'logout_reason' => $reason]);
        $token->delete();

        return response()->json(['success' => false, 'message' => $message, 'code' => $code], 401);
    }
}
