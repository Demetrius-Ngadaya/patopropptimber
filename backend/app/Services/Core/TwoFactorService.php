<?php

namespace App\Services\Core;

use App\Exceptions\BusinessException;
use App\Models\Core\User;
use App\Support\Security\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Two-factor login with an authenticator app, plus single-use recovery codes.
 * After the password check, login returns a short-lived challenge instead of
 * a token; the token is only issued once the 6-digit code is verified.
 */
class TwoFactorService
{
    private const CHALLENGE_MINUTES = 5;

    private const CHALLENGE_ATTEMPTS = 5;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public static function requiredFor(User $user): bool
    {
        if (! $user->isStaff() || ! $user->role) {
            return false;
        }
        $roles = array_filter(array_map('trim', explode(',', (string) app(SettingsService::class)->get('two_factor_required_roles'))));

        return in_array($user->role->slug, $roles, true);
    }

    /** Step 1: create a secret (not active until confirmed with a code). */
    public function begin(User $user): array
    {
        if ($user->hasTwoFactor()) {
            throw new BusinessException(__('Two-factor login is already on. Turn it off first to set it up again.'));
        }
        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        $issuer = (string) $this->settings->get('business_name') ?: config('app.name');

        return [
            'secret' => $secret,
            'otpauth_url' => Totp::uri($secret, $user->username ?: ($user->email ?: (string) $user->id), $issuer),
        ];
    }

    /** Step 2: prove the app works; returns recovery codes (shown once). */
    public function confirm(User $user, string $code): array
    {
        if (! $user->two_factor_secret || $user->hasTwoFactor()) {
            throw new BusinessException(__('Start the two-factor setup first.'));
        }
        $step = Totp::verify($user->two_factor_secret, $code);
        if ($step === null) {
            throw new BusinessException(__('The code is not correct. Check the time on your phone and try again.'), 'INVALID_CODE');
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes),
        ])->save();
        $this->audit->event('security.2fa_enabled', __('Two-factor login turned on'), $user, [], 'auth');

        return $codes;
    }

    public function disable(User $user, string $password, string $code): void
    {
        if (! Hash::check($password, $user->password)) {
            throw new BusinessException(__('Your current password is not correct.'));
        }
        if (! $this->checkCode($user, $code)) {
            throw new BusinessException(__('The code is not correct. Check the time on your phone and try again.'), 'INVALID_CODE');
        }
        if (self::requiredFor($user)) {
            throw new BusinessException(__('Your role must use two-factor login, so it cannot be turned off.'), 'TWO_FACTOR_REQUIRED');
        }
        $this->reset($user);
        $this->audit->event('security.2fa_disabled', __('Two-factor login turned off'), $user, [], 'auth');
    }

    /** Administrator reset for a user who lost their phone. */
    public function reset(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
    }

    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        if (! $user->hasTwoFactor() || ! $this->checkCode($user, $code, allowRecovery: false)) {
            throw new BusinessException(__('The code is not correct. Check the time on your phone and try again.'), 'INVALID_CODE');
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes)])->save();

        return $codes;
    }

    public function createChallenge(User $user): string
    {
        $token = Str::random(64);
        Cache::put($this->key($token), ['user_id' => $user->id, 'attempts' => 0], now()->addMinutes(self::CHALLENGE_MINUTES));

        return $token;
    }

    /** Resolve a challenge with a code; the challenge is single-use and limited to a few attempts. */
    public function passChallenge(string $challenge, string $code): User
    {
        $key = $this->key($challenge);
        $data = Cache::get($key);
        if (! $data) {
            throw new BusinessException(__('The sign-in step has expired. Please log in again.'), 'CHALLENGE_EXPIRED', 422);
        }
        $user = User::query()->find($data['user_id']);
        if (! $user || ! $user->is_active || ! $user->hasTwoFactor()) {
            Cache::forget($key);
            throw new BusinessException(__('The sign-in step has expired. Please log in again.'), 'CHALLENGE_EXPIRED', 422);
        }

        if (! $this->checkCode($user, $code)) {
            $data['attempts']++;
            if ($data['attempts'] >= self::CHALLENGE_ATTEMPTS) {
                Cache::forget($key);
                $this->audit->event('login.2fa_failed', __('Too many wrong two-factor codes'), $user, [], 'auth', $user->id, $user->name);
                throw new BusinessException(__('Too many wrong codes. Please log in again.'), 'CHALLENGE_EXPIRED', 422);
            }
            Cache::put($key, $data, now()->addMinutes(self::CHALLENGE_MINUTES));
            throw new BusinessException(__('The code is not correct. Check the time on your phone and try again.'), 'INVALID_CODE', 422);
        }
        Cache::forget($key);

        return $user;
    }

    /** Accepts a current authenticator code, or (once) a recovery code. */
    private function checkCode(User $user, string $code, bool $allowRecovery = true): bool
    {
        $step = Totp::verify((string) $user->two_factor_secret, $code, $user->two_factor_last_step ? (int) $user->two_factor_last_step : null);
        if ($step !== null) {
            $user->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }
        if (! $allowRecovery) {
            return false;
        }

        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $codes = (array) $user->two_factor_recovery_codes;
        foreach ($codes as $i => $hash) {
            if (Hash::check($clean, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                $this->audit->event('security.2fa_recovery_used', __('A recovery code was used to sign in'), $user, ['remaining' => count($codes)], 'auth', $user->id, $user->name);

                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> e.g. "K7Q2-M9XD" */
    private function newRecoveryCodes(): array
    {
        return array_map(fn () => strtoupper(Str::random(4).'-'.Str::random(4)), range(1, 8));
    }

    private function key(string $challenge): string
    {
        return 'mauzo:2fa:'.hash('sha256', $challenge);
    }
}
