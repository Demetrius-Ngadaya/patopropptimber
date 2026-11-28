<?php

namespace App\Platform;

use App\Models\Catalog\Product;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Platform\Console\CreateBusinessCommand;
use App\Platform\Console\InstallPlatformCommand;
use App\Platform\Console\ListBusinessesCommand;
use App\Platform\Console\MigrateBusinessesCommand;
use App\Platform\Console\ProvisionPendingCommand;
use App\Platform\Console\RefreshTemplateCommand;
use App\Platform\Console\RunForBusinessesCommand;
use App\Platform\Support\BusinessManager;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * PatoPro as one app for many businesses (PATOPRO_MODE=platform), and package
 * limits in both modes. In single mode the only visible effect is that the
 * limits in config/patopro.php are respected (none by default).
 */
class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Normally loaded by Composer; this also covers a server where `composer dump-autoload` has not run yet.
        require_once __DIR__.'/helpers.php';

        $this->app->singleton(BusinessManager::class);

        if (BusinessManager::platformMode()) {
            // The central database, next to each business's own database (the default connection).
            $base = config('database.connections.'.config('database.default'), []);
            config(['database.connections.'.config('patopro.platform.connection') => array_merge(
                $base,
                array_filter(config('patopro.platform.database'), fn ($v) => $v !== null),
            )]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallPlatformCommand::class,
                CreateBusinessCommand::class,
                ListBusinessesCommand::class,
                MigrateBusinessesCommand::class,
                RunForBusinessesCommand::class,
                RefreshTemplateCommand::class,
                ProvisionPendingCommand::class,
            ]);
            $this->chooseBusinessForCommand();
        }

        $this->enforceLimits();
    }

    /**
     * A command started by platform:run (or by hand) for one business:
     * PATOPRO_BUSINESS=dukalajuma php artisan …
     * Commands that the scheduler starts inherit the variable, so they run for the same business.
     */
    private function chooseBusinessForCommand(): void
    {
        $slug = getenv('PATOPRO_BUSINESS') ?: ($_SERVER['PATOPRO_BUSINESS'] ?? null);
        if (! $slug || ! BusinessManager::platformMode()) {
            return;
        }

        $manager = $this->app->make(BusinessManager::class);
        $business = $manager->findBySlug((string) $slug);
        if (! $business) {
            throw new RuntimeException("No business '$slug' on this platform.");
        }
        $manager->switchTo($business);
    }

    /** Adding one more user, branch or product beyond what the package allows is refused. */
    private function enforceLimits(): void
    {
        $count = [
            'users' => fn () => User::query()->where('type', User::TYPE_STAFF)->whereNotIn('id', User::developerIds())->count(),
            'branches' => fn () => Branch::query()->count(),
            'products' => fn () => Product::query()->count(),
        ];

        User::creating(function (User $user) use ($count): void {
            if ($user->type === User::TYPE_STAFF && ! $this->skipLimits() && ! $user->isDeveloper()) {
                plan()->ensureWithin('users', $count['users']);
            }
        });
        // (These listeners return nothing: returning false from "creating" would silently cancel the save.)
        Branch::creating(function () use ($count): void {
            if (! $this->skipLimits()) {
                plan()->ensureWithin('branches', $count['branches']);
            }
        });
        Product::creating(function () use ($count): void {
            if (! $this->skipLimits()) {
                plan()->ensureWithin('products', $count['products']);
            }
        });
    }

    /** Setting up a business (migrations, seeding) and tests are not limited. */
    private function skipLimits(): bool
    {
        return $this->app->runningInConsole() && ! $this->app->runningUnitTests();
    }
}
