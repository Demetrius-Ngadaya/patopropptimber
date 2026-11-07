<?php

namespace App\Console\Commands;

use App\Services\Finance\FixedAssetService;
use Illuminate\Console\Command;

class RunDepreciation extends Command
{
    protected $signature = 'mauzo:depreciate {period? : YYYY-MM, defaults to last month}';

    protected $description = 'Post monthly depreciation for all active fixed assets (catches up missed months)';

    public function handle(FixedAssetService $assets): int
    {
        $period = $this->argument('period') ?: now()->subMonthNoOverflow()->format('Y-m');
        $result = $assets->depreciate($period);
        $this->info("Depreciation to {$period}: {$result['assets']} asset(s), {$result['months']} month(s), ".number_format($result['amount'], 2));

        return self::SUCCESS;
    }
}
