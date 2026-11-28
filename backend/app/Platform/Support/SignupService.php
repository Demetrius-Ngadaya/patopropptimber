<?php

namespace App\Platform\Support;

use App\Exceptions\BusinessException;
use App\Platform\Models\Business;
use App\Platform\Models\Plan;
use App\Platform\Models\Signup;
use App\Platform\Provisioning\BusinessProvisioner;
use App\Services\Core\SmsService;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Online registration of a new business:
 *   start  → details checked, SMS code sent to the owner's phone
 *   verify → code right: the business is created and set up (seconds with the template)
 *   status → the sign-up page waits on this until the business is ready
 */
class SignupService
{
    public function __construct(
        private readonly PlatformSms $sms,
        private readonly BusinessProvisioner $provisioner,
    ) {}

    private function cfg(string $key): mixed
    {
        return config('patopro.platform.signup.'.$key);
    }

    /**
     * @param  array{business_name: string, slug: string, owner_name: string, phone: string, email?: ?string, password: string, plan: string, billing_cycle?: ?string}  $input
     * @return array{signup: Signup, debug_code: ?string}
     */
    public function start(array $input, ?string $ip): array
    {
        if (! $this->cfg('enabled')) {
            throw new BusinessException(__('Registration is closed at the moment. Please contact SHEZONI.'), 'SIGNUP_CLOSED', 403);
        }

        $phone = self::phone($input['phone']);
        $slug = Slug::normalise($input['slug']);
        if ($problem = Slug::problem($slug)) {
            throw new BusinessException($problem, 'SLUG_UNAVAILABLE', 422);
        }

        $plan = Plan::query()->where('code', $input['plan'])->where('is_active', true)->where('is_public', true)->first();
        if (! $plan) {
            throw new BusinessException(__('Choose one of the packages shown.'), 'PLAN_NOT_FOUND', 422);
        }

        if (Business::query()->where('phone', $phone)->count() >= (int) $this->cfg('businesses_per_phone')) {
            throw new BusinessException(__('This phone number already has the most businesses allowed. Sign in to your business or contact SHEZONI.'), 'PHONE_LIMIT', 422);
        }
        if (Signup::query()->where('phone', $phone)->where('created_at', '>', now()->subDay())->count() >= (int) $this->cfg('per_phone_per_day')) {
            throw new BusinessException(__('Too many registrations from this phone today. Try again tomorrow.'), 'TOO_MANY_SIGNUPS', 429);
        }

        $code = self::newCode();
        $signup = Signup::query()->create([
            'phone' => $phone,
            'slug' => $slug,
            'data' => [
                'business_name' => trim($input['business_name']),
                'owner_name' => trim($input['owner_name']),
                'email' => ($input['email'] ?? null) ?: null,
                'password' => Hash::make($input['password']),
                'plan_id' => $plan->id,
                'billing_cycle' => ($input['billing_cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly',
            ],
            'code_hash' => Signup::hashCode($code),
            'code_sent_at' => now(),
            'expires_at' => now()->addMinutes((int) $this->cfg('code_minutes')),
            'ip' => $ip,
        ]);

        try {
            return ['signup' => $signup, 'debug_code' => $this->sendCode($signup, $code)];
        } catch (BusinessException $e) {
            $signup->delete(); // nothing was sent: this attempt does not count

            throw $e;
        }
    }

    /** @return array{signup: Signup, debug_code: ?string} */
    public function resend(Signup $signup): array
    {
        $this->ensureOpen($signup);
        if ($signup->codes_sent >= (int) $this->cfg('max_codes')) {
            throw new BusinessException(__('No more codes can be sent. Start the registration again.'), 'TOO_MANY_CODES', 429);
        }
        $wait = (int) $this->cfg('resend_seconds') - (int) $signup->code_sent_at->diffInSeconds(now(), true);
        if ($wait > 0) {
            throw new BusinessException(__('Wait :seconds seconds before asking for another code.', ['seconds' => $wait]), 'WAIT', 429);
        }

        $code = self::newCode();
        $signup->update([
            'code_hash' => Signup::hashCode($code),
            'code_sent_at' => now(),
            'codes_sent' => $signup->codes_sent + 1,
            'attempts' => 0,
            'expires_at' => now()->addMinutes((int) $this->cfg('code_minutes')),
        ]);

        return ['signup' => $signup, 'debug_code' => $this->sendCode($signup, $code)];
    }

    /** The code is right: create the business and set it up. */
    public function verify(Signup $signup, string $code): Business
    {
        if ($signup->verified_at && $signup->business) {
            return $signup->business; // asked twice (double tap, page refresh)
        }
        $this->ensureOpen($signup);

        if (! $signup->codeMatches($code)) {
            $signup->increment('attempts');
            $left = (int) $this->cfg('max_attempts') - $signup->attempts;
            throw new BusinessException($left > 0
                ? __('The code is not correct. :left attempt(s) left.', ['left' => $left])
                : __('The code is not correct. Start the registration again.'), 'WRONG_CODE', 422);
        }

        // Someone may have taken the address while the code was on its way.
        if ($problem = Slug::problem($signup->slug)) {
            throw new BusinessException($problem, 'SLUG_UNAVAILABLE', 422);
        }

        $plan = Plan::query()->findOrFail($signup->data['plan_id']);
        $business = Business::query()->create([
            'slug' => $signup->slug,
            'name' => $signup->data['business_name'],
            'owner_name' => $signup->data['owner_name'],
            'phone' => $signup->phone,
            'email' => $signup->data['email'] ?? null,
            'plan_id' => $plan->id,
            'billing_cycle' => $signup->data['billing_cycle'] ?? 'monthly',
            'db_name' => BusinessProvisioner::databaseName($signup->slug),
            'trial_ends_at' => now()->addDays($plan->trialDays())->endOfDay(),
            'status' => 'provisioning',
            'meta' => ['source' => 'signup'],
        ]);
        $signup->update(['verified_at' => now(), 'business_id' => $business->id]);

        // Usually takes seconds. If it fails or is slow, platform:provision finishes it within minutes.
        try {
            @set_time_limit(300);
            if ($this->provisioner->provision($business, $signup->owner())) {
                $signup->sendWelcome($business->refresh());
            }
        } catch (Throwable) {
            // recorded on the business; the page shows "still preparing" and the scheduler retries
        }

        return $business->refresh();
    }

    /** What the sign-up page shows while it waits. */
    public function status(Signup $signup): array
    {
        $business = $signup->business;

        return [
            'id' => $signup->id,
            'phone' => $signup->maskedPhone(),
            'verified' => (bool) $signup->verified_at,
            'expires_at' => $signup->expires_at->toIso8601String(),
            'resend_after' => max(0, (int) config('patopro.platform.signup.resend_seconds') - (int) $signup->code_sent_at->diffInSeconds(now(), true)),
            'codes_left' => max(0, (int) $this->cfg('max_codes') - $signup->codes_sent),
            'status' => $business?->status === 'ready' ? 'ready' : ($business ? 'preparing' : 'waiting_for_code'),
            'business' => $business ? [
                'slug' => $business->slug,
                'name' => $business->name,
                'url' => $business->url(),
                'login_url' => $business->url('login'),
                'trial_ends_at' => $business->trial_ends_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function ensureOpen(Signup $signup): void
    {
        if ($signup->verified_at) {
            throw new BusinessException(__('This registration is already confirmed.'), 'ALREADY_VERIFIED', 409);
        }
        if ($signup->expires_at->isPast()) {
            throw new BusinessException(__('The code has expired. Ask for a new code.'), 'CODE_EXPIRED', 422);
        }
        if ($signup->attempts >= (int) $this->cfg('max_attempts')) {
            throw new BusinessException(__('Too many wrong codes. Start the registration again.'), 'TOO_MANY_ATTEMPTS', 429);
        }
    }

    /** Returns the code itself only where SMS is off and the app is not in production (testing on a PC). */
    private function sendCode(Signup $signup, string $code): ?string
    {
        $sent = $this->sms->send($signup->phone, __('PatoPro: your confirmation code is :code. It expires in :minutes minutes. Do not share it.', [
            'code' => $code,
            'minutes' => (int) $this->cfg('code_minutes'),
        ]));
        if ($sent) {
            return null;
        }
        if (! app()->isProduction()) {
            return $code;
        }

        throw new BusinessException(__('We could not send the SMS code right now. Please try again in a few minutes.'), 'SMS_FAILED', 503);
    }

    private static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function phone(string $phone): string
    {
        $normal = SmsService::normalizePhone($phone);
        if (! $normal || ! preg_match('/^255[67]\d{8}$/', $normal)) {
            throw new BusinessException(__('Enter a valid Tanzanian mobile number, e.g. 0712 345 678.'), 'BAD_PHONE', 422);
        }

        return $normal;
    }
}
