<?php

namespace App\Platform\Console;

use App\Platform\Models\Business;
use App\Platform\Support\BusinessManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * php artisan platform:migrate            — central tables, then every business's database
 * php artisan platform:migrate --business=dukalajuma
 * Used by update-all.sh after git pull.
 */
class MigrateBusinessesCommand extends Command
{
    protected $signature = 'platform:migrate {--business= : Only this business} {--seed : Also run the base seeders (safe to repeat)}';

    protected $description = 'Run new migrations on the central database and on every business database';

    public function handle(BusinessManager $manager): int
    {
        if (! BusinessManager::platformMode()) {
            $this->error('Not in platform mode; use php artisan migrate.');

            return self::FAILURE;
        }

        if (! $this->option('business')) {
            $this->info('Central database');
            Artisan::call('migrate', ['--database' => config('patopro.platform.connection'), '--path' => 'database/migrations/platform', '--force' => true], $this->output);
        }

        $failed = 0;
        $businesses = Business::query()->where('status', 'ready')
            ->when($this->option('business'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            $this->info("{$business->slug} ({$business->db_name})");
            try {
                $manager->run($business, function () {
                    Artisan::call('migrate', ['--force' => true], $this->output);
                    if ($this->option('seed')) {
                        Artisan::call('db:seed', ['--force' => true], $this->output);
                    }
                });
            } catch (Throwable $e) {
                $failed++;
                $this->error("  failed: {$e->getMessage()}");
            }
        }

        $this->line(sprintf('%d business(es), %d failed.', $businesses->count(), $failed));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
