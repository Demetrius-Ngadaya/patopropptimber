<?php

namespace App\Platform\Console;

use App\Platform\Models\Business;
use App\Platform\Support\BusinessManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Run any artisan command once for every business, each on its own data.
 *
 *   php artisan platform:run schedule:run          ← the one cron line of the platform
 *   php artisan platform:run cache:clear --business=dukalajuma
 *
 * Each business runs in its own process with PATOPRO_BUSINESS set, and the
 * commands its scheduler starts inherit it, so they run for the same business.
 */
class RunForBusinessesCommand extends Command
{
    protected $signature = 'platform:run {cmd* : The command and its arguments} {--business= : Only this business}';

    protected $description = 'Run an artisan command for every business on the platform';

    public function handle(): int
    {
        if (! BusinessManager::platformMode()) {
            $this->error('Not in platform mode.');

            return self::FAILURE;
        }

        $parts = (array) $this->argument('cmd');
        $command = implode(' ', $parts);

        $failed = 0;
        $businesses = Business::query()->with('plan')->where('status', 'ready')
            ->when($this->option('business'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get()
            ->filter(fn (Business $b) => $b->state()->canOpen());

        foreach ($businesses as $business) {
            // A separate process per business: its own database, settings, cache and schedule.
            $result = Process::path(base_path())
                ->env(['PATOPRO_BUSINESS' => $business->slug])
                ->timeout(3600)
                ->run(array_merge(self::php(), ['artisan'], $parts));

            if ($this->getOutput()->isVerbose() && trim($result->output()) !== '') {
                $this->line("[{$business->slug}] ".trim($result->output()));
            }
            if (! $result->successful()) {
                $failed++;
                $this->error("{$business->slug}: ".trim($result->errorOutput() ?: $result->output()));
            }
        }

        if ($this->getOutput()->isVerbose() || $failed) {
            $this->line(sprintf('%s: %d business(es), %d failed.', $command, $businesses->count(), $failed));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The PHP to start: the same one running now. Where the host disables proc_open
     * and this command was started through ~/bin/php (which lifts that), the child
     * gets the same treatment, because its scheduler also starts processes.
     *
     * @return array<int, string>
     */
    public static function php(): array
    {
        $php = getenv('PHP_BINARY') ?: PHP_BINARY;

        return ini_get('disable_functions') === '' ? [$php, '-d', 'disable_functions='] : [$php];
    }
}
