<?php

namespace Database\Seeders;

use Database\Seeders\Catalog\UnitSeeder;
use Database\Seeders\Core\AdminUserSeeder;
use Database\Seeders\Core\BranchSeeder;
use Database\Seeders\Core\RoleSeeder;
use Database\Seeders\Finance\ChartOfAccountsSeeder;
use Database\Seeders\Hr\HrDefaultsSeeder;
use Illuminate\Database\Seeder;

/**
 * Production-safe base data. Re-running it never duplicates rows and never
 * changes data an administrator has edited (roles, accounts, passwords).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seeders = [
            RoleSeeder::class,
            BranchSeeder::class,
            AdminUserSeeder::class,
            UnitSeeder::class,
            ChartOfAccountsSeeder::class,
        ];
        if (class_exists(HrDefaultsSeeder::class)) {
            $seeders[] = HrDefaultsSeeder::class;
        }

        $this->call($seeders, silent: app()->runningUnitTests());
    }
}
