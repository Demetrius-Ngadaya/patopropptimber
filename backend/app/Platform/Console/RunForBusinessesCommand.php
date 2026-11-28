<?php

namespace App\Platform\Console;

use App\Platform\Models\Business;
use App\Platform\Support\BusinessManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Run any artisan command once for every business, each on its own data.
 *
 *   php artisan platform:run schedule:run          ← the one cron line of the platform
 *   php artisan platform:run cache:clear --business=dukalajuma
 *
 * Commands that the scheduler starts in the background get PATOPRO_BUSINESS,
 * so they run for the same business.
 */
class RunForBusinessesCommand extends Command
{
    protected $signature = 'platform:run {cmd* : The command and its arguments} {--business= : Only this business}';

    protected $description = 'Run an artisan command for every business on the platform';

    public function handle(BusinessManager $manager): int
    {
        if (! BusinessManager::platformMode()) {
            $this->error('Not in platform mode.');

            return self::FAILURE;
        }

        $parts = (array) $this->argument('cmd');
        $command = implode(' ', array_map(fn ($p) => str_contains($p, ' ') ? escapeshellarg($p) : $p, $parts));

        $failed = 0;
        $businesses = Business::query()->with('plan')->where('status', 'ready')
            ->when($this->option('business'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get()
            ->filter(fn (Business $b) => $b->state()->canOpen());

        foreach ($businesses as $business) {
            try {
                $manager->run($business, function (Business $business) use ($command) {
                    putenv('PATOPRO_BUSINESS='.$business->slug);
                    $_SERVER['PATOPRO_BUSINESS'] = $_ENV['PATOPRO_BUSINESS'] = $business->slug;
                    Artisan::call($command, [], $this->getOutput()->isVerbose() ? $this->output : null);
                });
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$business->slug}: {$e->getMessage()}");
                report($e);
            } finally {
                putenv('PATOPRO_BUSINESS');
                unset($_SERVER['PATOPRO_BUSINESS'], $_ENV['PATOPRO_BUSINESS']);
            }
        }

        if ($this->getOutput()->isVerbose() || $failed) {
            $this->line(sprintf('%s: %d business(es), %d failed.', $command, $businesses->count(), $failed));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
