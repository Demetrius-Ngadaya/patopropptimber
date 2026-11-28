<?php

namespace Tests\Feature\Platform;

use App\Platform\Console\InstallPlatformCommand;
use App\Platform\Models\Business;
use App\Platform\Models\Plan;
use App\Platform\Support\BusinessManager;
use App\Services\Core\SettingsService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * The platform with SQLite files: one central database and one per business,
 * in a temporary folder removed after each test.
 */
abstract class PlatformTestCase extends BaseTestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/patopro-platform-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        touch($this->dir.'/central.sqlite');

        config([
            'patopro.mode' => 'platform',
            'patopro.platform.url' => 'https://patopro.test',
            'patopro.platform.provisioning.driver' => 'none',
            'patopro.platform.business_database.prefix' => $this->dir.'/pp_',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.platform' => ['driver' => 'sqlite', 'database' => $this->dir.'/central.sqlite', 'prefix' => '', 'foreign_key_constraints' => true],
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        $this->app->forgetInstance(BusinessManager::class);

        // Routes that exist only in platform mode (the app booted in single mode).
        Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api/platform.php'));
        $this->app['router']->getRoutes()->refreshNameLookups();

        Artisan::call('migrate', ['--database' => 'platform', '--path' => 'database/migrations/platform', '--force' => true]);
        foreach (InstallPlatformCommand::examplePlans() as $i => $plan) {
            Plan::query()->create($plan + ['sort_order' => $i + 1]);
        }
    }

    protected function tearDown(): void
    {
        app(BusinessManager::class)->forget();
        DB::disconnect('platform');
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** A ready business with its own migrated and seeded database. */
    protected function business(string $slug, string $name, string $plan, array $dates): Business
    {
        $file = $this->dir."/$slug.sqlite";
        touch($file);
        $business = Business::query()->create([
            'slug' => $slug, 'name' => $name, 'plan_id' => Plan::query()->where('code', $plan)->value('id'),
            'db_name' => $file, 'status' => 'ready',
        ] + $dates);

        app(BusinessManager::class)->run($business, function () use ($name) {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--force' => true]);
            app(SettingsService::class)->update(['business_name' => $name]);
        });

        return $business;
    }
}
