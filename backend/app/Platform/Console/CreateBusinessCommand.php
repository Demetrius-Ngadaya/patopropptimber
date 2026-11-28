<?php

namespace App\Platform\Console;

use App\Platform\Models\Business;
use App\Platform\Models\Plan;
use App\Platform\Provisioning\BusinessProvisioner;
use App\Platform\Support\BusinessManager;
use App\Platform\Support\Slug;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * php artisan platform:business:create dukalajuma "Duka la Juma" --plan=biashara --owner="Juma Mussa" --phone=0712345678
 *
 * Registers the business, prepares its own database (tables and base data)
 * and sets the owner's sign-in. The database is created the way
 * PLATFORM_DB_PROVISIONER says (DirectAdmin API by default).
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
        {--db= : Database name (default: prefix + slug)}';

    protected $description = 'Add a business to the platform and prepare its database';

    public function handle(BusinessProvisioner $provisioner): int
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

        $db = (string) ($this->option('db') ?: BusinessProvisioner::databaseName($slug));
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

        $password = (string) ($this->option('password') ?: Str::password(10, symbols: false));
        try {
            $provisioner->provision($business, [
                'name' => $business->owner_name,
                'phone' => $business->phone,
                'email' => $business->email,
                'password' => $password,
                'must_change_password' => ! $this->option('password'),
            ], fn (string $line) => $this->line("  $line"));
        } catch (Throwable $e) {
            $this->error('Setting up the database failed: '.$e->getMessage());
            $this->line('Fix the cause, then run the same command again.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Ready: {$business->url()}");
        $this->line("Package: {$plan->name}, trial until {$business->trial_ends_at->toDateString()}");
        $this->line('Owner sign-in: admin'.($business->phone ? " (or {$business->phone})" : '')."  password: $password");

        return self::SUCCESS;
    }
}
