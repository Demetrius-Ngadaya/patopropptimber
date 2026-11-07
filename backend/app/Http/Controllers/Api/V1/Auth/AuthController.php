<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Core\LoginLog;
use App\Models\Core\PersonalAccessToken;
use App\Models\Core\User;
use App\Models\Mobile\MobileDevice;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use App\Services\Core\TwoFactorService;
use App\Support\UserPayload;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = trim((string) $request->input('login'));
        $user = User::query()
            ->where(fn ($q) => $q->where('username', $identifier)->orWhere('email', $identifier)->orWhere('phone', $identifier))
            ->first();

        // The same message whether the account exists or not, so accounts cannot be discovered.
        $invalid = __('Invalid username or password.');

        if (! $user) {
            Hash::check((string) $request->input('password'), '$2y$12$'.str_repeat('a', 53)); // equalise timing
            $this->failLogin($request, $identifier, 'failed', 'unknown user', $invalid);
        }

        if ($user->isLocked()) {
            $minutes = (int) ceil(now()->diffInMinutes($user->locked_until, true)) ?: 1;
            $this->failLogin($request, $identifier, 'locked', 'account locked', __('Too many failed attempts. Try again in :minutes minute(s) or ask the administrator to unlock your account.', ['minutes' => $minutes]), $user);
        }

        if (! Hash::check((string) $request->input('password'), $user->password)) {
            $user->failed_login_attempts++;
            if ($user->failed_login_attempts >= $this->settings->int('max_login_attempts')) {
                $user->locked_until = now()->addMinutes($this->settings->int('lockout_minutes'));
                $user->failed_login_attempts = 0;
            }
            $user->saveQuietly();
            $this->failLogin($request, $identifier, 'failed', 'wrong password', $user->locked_until?->isFuture()
                ? __('Too many failed attempts. Your account is locked for :minutes minutes.', ['minutes' => $this->settings->int('lockout_minutes')])
                : $invalid, $user);
        }

        if (! $user->is_active) {
            $this->failLogin($request, $identifier, 'inactive', 'account disabled', __('Your account is deactivated. Contact the administrator.'), $user);
        }
        if ($user->isStaff() && ! $user->role) {
            $this->failLogin($request, $identifier, 'inactive', 'no role', __('Your account has no role assigned. Contact the administrator.'), $user);
        }

        // Password is right: with two-factor on, ask for the code before issuing a token.
        if ($user->hasTwoFactor()) {
            $user->forceFill(['failed_login_attempts' => 0])->saveQuietly();

            return $this->ok([
                'two_factor_required' => true,
                'challenge' => $this->twoFactor->createChallenge($user),
                'expires_in' => 300,
            ], __('Enter the 6-digit code from your authenticator app.'));
        }

        return $this->issueToken($request, $user, $identifier);
    }

    /** Second login step for accounts with two-factor login. */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $this->twoFactor->passChallenge($data['challenge'], $data['code']);

        return $this->issueToken($request, $user, $user->username ?? (string) $user->id);
    }

    private function issueToken(Request $request, User $user, string $identifier): JsonResponse
    {
        if ($this->settings->bool('single_session')) {
            $user->tokens()->each(function (PersonalAccessToken $token) {
                LoginLog::query()->where('token_id', $token->id)->whereNull('logged_out_at')->update(['logged_out_at' => now(), 'logout_reason' => 'revoked']);
                $token->delete();
            });
        }

        // The mobile app sells offline for days, so its login lasts longer (the app has its own lock).
        $mobile = $user->isStaff() && $request->input('client') === 'mobile';
        $hours = match (true) {
            $user->isCustomer() => 24 * 30,
            $mobile => 24 * max(1, $this->settings->int('mobile_session_days')),
            default => 24,
        };
        $name = Str::limit($request->input('device_name') ?: ($request->userAgent() ?: 'web'), 90, '');
        $newToken = $user->createToken($mobile ? PersonalAccessToken::MOBILE_PREFIX.$name : $name, ['*'], now()->addHours($hours));
        $newToken->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 495, ''),
            'last_activity_at' => now(),
        ])->save();

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        LoginLog::create([
            'user_id' => $user->id,
            'identifier' => Str::limit($identifier, 190, ''),
            'status' => 'success',
            'branch_id' => $user->branch_id,
            'token_id' => $newToken->accessToken->id,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 495, ''),
            'logged_in_at' => now(),
        ]);
        $this->audit->event('login.success', __(':user logged in', ['user' => $user->name]), $user, ['two_factor' => $user->hasTwoFactor()], 'auth', $user->id, $user->name);

        return $this->ok([
            'token' => $newToken->plainTextToken,
            'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
            'user' => UserPayload::make($user),
        ], __('Welcome back, :user.', ['user' => $user->name]));
    }

    private function failLogin(Request $request, string $identifier, string $status, string $reason, string $message, ?User $user = null): never
    {
        LoginLog::create([
            'user_id' => $user?->id,
            'identifier' => Str::limit($identifier, 190, ''),
            'status' => $status,
            'failure_reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 495, ''),
        ]);
        $this->audit->event('login.'.$status, "Login {$status} for '".Str::limit($identifier, 100)."': {$reason}", $user, [], 'auth', $user?->id, $user?->name);

        throw new BusinessException($message, 'LOGIN_FAILED', 422);
    }

    // --------------------------------------------------------- two-factor setup

    public function twoFactorSetup(Request $request): JsonResponse
    {
        return $this->ok($this->twoFactor->begin($request->user()), __('Scan the QR code with your authenticator app, then enter the 6-digit code.'));
    }

    public function twoFactorConfirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $codes = $this->twoFactor->confirm($request->user(), $data['code']);

        return $this->ok(['recovery_codes' => $codes], __('Two-factor login is on. Keep the recovery codes somewhere safe: each works once if you lose your phone.'));
    }

    public function twoFactorDisable(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        $this->twoFactor->disable($request->user(), $data['password'], $data['code']);

        return $this->ok(null, __('Two-factor login is off.'));
    }

    public function twoFactorRecoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        return $this->ok(['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($request->user(), $data['code'])], __('New recovery codes created. The old ones no longer work.'));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(UserPayload::make($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            LoginLog::query()->where('token_id', $token->id)->whereNull('logged_out_at')
                ->update(['logged_out_at' => now(), 'logout_reason' => 'manual']);
            // A phone signed out with this login stops getting notifications.
            MobileDevice::query()->where('token_id', $token->id)->delete();
            $token->delete();
        }
        $this->audit->event('logout', "{$user->name} logged out", $user, [], 'auth');

        return $this->ok(null, __('You have been logged out.'));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::defaults()],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw new BusinessException(__('Your current password is not correct.'));
        }

        $user->forceFill([
            'password' => $data['password'],
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();

        // Sign out every other device.
        $current = $user->currentAccessToken();
        $user->tokens()->where('id', '!=', $current?->id)->delete();
        $this->audit->event('password.changed', "{$user->name} changed their password", $user, [], 'security');

        return $this->ok(null, __('Password changed. Other devices have been signed out.'));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
        ]);

        $user->fill($data)->save();
        if ($user->isCustomer() && $user->customer) {
            $user->customer->fill(array_intersect_key($data, array_flip(['name', 'email', 'phone'])))->save();
        }

        return $this->ok(UserPayload::make($user->fresh()), __('Profile updated.'));
    }

    /** Language and light / dark theme, remembered on the account across devices. */
    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['sometimes', Rule::in(['en', 'sw'])],
            'theme' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
        ]);
        $request->user()->forceFill($data)->saveQuietly();
        app()->setLocale($request->user()->locale);

        return $this->ok(UserPayload::make($request->user()->fresh()), __('Preferences saved.'));
    }

    /** Active sessions of the signed-in user (devices). */
    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        return $this->ok($request->user()->tokens()->latest('last_activity_at')->get()
            ->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'device' => $t->name,
                'ip_address' => $t->ip_address,
                'last_activity_at' => $t->last_activity_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
                'current' => $t->id === $currentId,
            ]));
    }

    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($tokenId)->firstOrFail();
        LoginLog::query()->where('token_id', $token->id)->whereNull('logged_out_at')->update(['logged_out_at' => now(), 'logout_reason' => 'revoked']);
        $token->delete();

        return $this->ok(null, __('The device has been signed out.'));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Same answer whether or not the email exists (no account enumeration).
        return $this->ok(null, __('If an account with that email exists, a reset link has been sent.'));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw new BusinessException(__('This password reset link is invalid or has expired.'));
        }

        return $this->ok(null, __('Your password has been reset. You can now log in.'));
    }
}
