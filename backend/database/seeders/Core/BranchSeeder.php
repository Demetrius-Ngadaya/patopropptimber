<?php

namespace Database\Seeders\Core;

use App\Models\Core\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->firstOrCreate(['code' => 'MAIN'], [
            'name' => 'Main Branch',
            'location' => 'Head office',
            'is_main' => true,
            'is_active' => true,
        ]);
    }
}
