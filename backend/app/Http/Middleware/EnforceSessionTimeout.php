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
        if (! $token->last_activity_at || $token->last_activity_at->diffInSeconds(now(), true) >= 30) {
            $token->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        $response = $next($request);
        $response->headers->set('X-Session-Timeout', (string) $minutes);

        return $response;
    }

    private function end(PersonalAccessToken $token, string $reason, string $message, string $code): Response
    {
        LoginLog::query()->where('token_id', $token->id)->whereNull('logged_out_at')
            ->update(['logged_out_at' => now(), 'logout_reason' => $reason]);
        $token->delete();

        return response()->json(['success' => false, 'message' => $message, 'code' => $code], 401);
    }
}
