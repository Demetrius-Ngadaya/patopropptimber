<?php

namespace App\Platform\Console;

use App\Platform\Models\Business;
use Illuminate\Console\Command;

/** php artisan platform:list */
class ListBusinessesCommand extends Command
{
    protected $signature = 'platform:list';

    protected $description = 'List the businesses on the platform with their package and subscription state';

    public function handle(): int
    {
        $rows = Business::query()->with('plan')->orderBy('id')->get()->map(fn (Business $b) => [
            $b->id, $b->slug, $b->name, $b->plan?->name ?? '-', $b->status, $b->state()->value,
            $b->servedUntil()?->toDateString() ?? '-', $b->db_name,
        ]);
        $this->table(['#', 'Address', 'Name', 'Package', 'Setup', 'State', 'Until', 'Database'], $rows);

        return self::SUCCESS;
    }
}
