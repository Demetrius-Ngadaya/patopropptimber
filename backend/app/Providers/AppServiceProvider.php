<?php

namespace App\Providers;

use App\Models\Core\PersonalAccessToken;
use App\Services\Core\SettingsService;
use App\Services\Mobile\SyncPusher;
use App\Support\BranchContext;
use App\Support\MorphMap;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BranchContext::class);
        $this->app->scoped(SettingsService::class);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Production always talks HTTPS (links in e-mails, file URLs, redirects).
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Short, stable names in polymorphic columns and audit logs.
        Relation::enforceMorphMap(MorphMap::map());

        $this->configureRateLimiting();
        $this->configurePasswords();

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return rtrim(config('mauzo.frontend_url'), '/').'/reset-password?token='.$token.'&email='.urlencode($user->email);
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // Offline changes replayed by the mobile sync were already counted once, on the sync call.
            if ($request->attributes->get(SyncPusher::REPLAY_ATTRIBUTE) === true) {
                return Limit::none();
            }

            return Limit::perMinute(240)->by($request->user()?->id ?: $request->ip());
        });

        // Brute-force protection on top of the per-account lockout.
        RateLimiter::for('login', function (Request $request) {
            $identifier = strtolower((string) $request->input('login'));

            return [
                Limit::perMinute(10)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-id:'.$identifier.'|'.$request->ip()),
            ];
        });

        // Second login step: a challenge also dies after 5 wrong codes.
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(15)->by('2fa:'.$request->ip());
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('exports', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    }

    private function configurePasswords(): void
    {
        Password::defaults(function () {
            $min = 8;
            try {
                $min = max(6, app(SettingsService::class)->int('password_min_length'));
            } catch (\Throwable) {
                // settings table not migrated yet
            }

            $rule = Password::min($min)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
