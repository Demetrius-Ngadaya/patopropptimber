<?php

namespace App\Platform\Console;

use App\Models\Core\User;
use App\Platform\Models\Business;
use App\Platform\Models\Plan;
use App\Platform\Support\BusinessManager;
use App\Platform\Support\Slug;
use App\Services\Core\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * php artisan platform:business:create dukalajuma "Duka la Juma" --plan=biashara --owner="Juma Mussa" --phone=0712345678
 *
 * Registers the business, prepares its own database (tables and base data)
 * and sets the owner's sign-in. The database must already exist and be
 * granted to PLATFORM_BUSINESS_DB_USERNAME, unless --create-db works on this server.
 */
class CreateBusinessCommand extends Command
{
    protected $signature = 'platform:business:create
        {slug : Web address part: patopro.shezoni.com/<slug>}
        {name : Business name}
        {--plan= : Package code (default: the first active package)}
        {--owner= : Owner\'s full name}
        {--phone= : Owner\'s phone (sign-in)}
        {--email= : Owner\'s email}
        {--password= : Owner\'s password (default: a random one, changed at first sign-in)}
        {--db= : Database name (default: prefix + slug)}
        {--create-db : Try to create the database (needs CREATE privilege)}';

    protected $description = 'Add a business to the platform and prepare its database';

    public function handle(BusinessManager $manager): int
    {
        if (! BusinessManager::platformMode()) {
            $this->error('Set PATOPRO_MODE=platform in .env first.');

            return self::FAILURE;
        }

        $slug = Slug::normalise((string) $this->argument('slug'));

        // A business whose setup failed earlier is set up again with the same command.
        $existing = Business::query()->where('slug', $slug)->first();
        if ($existing && $existing->status === 'ready') {
            $this->error('This business already exists and is ready.');

            return self::FAILURE;
        }

        if ($problem = Slug::problem($slug, $existing?->id)) {
            $this->error($problem);

            return self::FAILURE;
        }

        $plan = $this->option('plan')
            ? Plan::query()->where('code', $this->option('plan'))->first()
            : Plan::query()->where('is_active', true)->orderBy('sort_order')->first();
        if (! $plan) {
            $this->error('Package not found. Run php artisan platform:install, or check --plan.');

            return self::FAILURE;
        }

        $db = (string) ($this->option('db') ?: config('patopro.platform.business_database.prefix').str_replace('-', '_', $slug));
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) {
            $this->error("Database name '$db' is not valid.");

            return self::FAILURE;
        }
        if (Business::query()->where('db_name', $db)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
            $this->error("Database '$db' is already used by another business.");

            return self::FAILURE;
        }

        $business = Business::query()->updateOrCreate(['slug' => $slug], [
            'name' => (string) $this->argument('name'),
            'owner_name' => $this->option('owner'),
            'phone' => $this->option('phone') ? Slug::phone((string) $this->option('phone')) : null,
            'email' => $this->option('email'),
            'plan_id' => $plan->id,
            'db_name' => $db,
            'trial_ends_at' => now()->addDays($plan->trialDays())->endOfDay(),
            'status' => 'provisioning',
        ]);
        $this->line("Business #{$business->id} registered. Preparing database $db…");

        try {
            if ($this->option('create-db')) {
                DB::connection(config('patopro.platform.connection'))
                    ->statement("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }

            $login = $manager->run($business, function (Business $business) {
                Artisan::call('migrate', ['--force' => true], $this->output);
                Artisan::call('db:seed', ['--force' => true], $this->output);

                return $this->setUpOwner($business);
            });

            $business->update(['status' => 'ready']);
        } catch (Throwable $e) {
            $business->update(['status' => 'failed', 'meta' => ['error' => mb_substr($e->getMessage(), 0, 500)]]);
            $this->error('Setting up the database failed: '.$e->getMessage());
            $this->line('Check that the database exists and the business database user can use it, then run the same command again.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Ready: {$business->url()}");
        $this->line("Package: {$plan->name}, trial until {$business->trial_ends_at->toDateString()}");
        $this->line("Owner sign-in: {$login['login']}  password: {$login['password']}");

        return self::SUCCESS;
    }

    /** The seeded administrator becomes the owner's account. */
    private function setUpOwner(Business $business): array
    {
        app(SettingsService::class)->update(['business_name' => $business->name]);

        $password = (string) ($this->option('password') ?: Str::password(10, symbols: false));
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $admin->forceFill(array_filter([
            'name' => $business->owner_name ?: $admin->name,
            'phone' => $business->phone,
            'email' => $business->email,
        ]) + [
            'password' => $password,
            'must_change_password' => ! $this->option('password'),
        ])->save();

        return ['login' => 'admin'.($business->phone ? " (or {$business->phone})" : ''), 'password' => $password];
    }
}
