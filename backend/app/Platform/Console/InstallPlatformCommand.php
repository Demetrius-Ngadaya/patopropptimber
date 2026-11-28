<?php

namespace App\Platform\Console;

use App\Platform\Models\Plan;
use App\Platform\Support\BusinessManager;
use Illuminate\Console\Command;

/** php artisan platform:install — create the central tables and the first packages. */
class InstallPlatformCommand extends Command
{
    protected $signature = 'platform:install';

    protected $description = 'Create the central platform tables (businesses, packages) and example packages';

    public function handle(): int
    {
        if (! BusinessManager::platformMode()) {
            $this->error('Set PATOPRO_MODE=platform in .env first.');

            return self::FAILURE;
        }
        if (! config('patopro.platform.database.database')) {
            $this->error('Set PLATFORM_DB_DATABASE (the central database) in .env first.');

            return self::FAILURE;
        }

        $this->call('migrate', [
            '--database' => config('patopro.platform.connection'),
            '--path' => 'database/migrations/platform',
            '--force' => true,
        ]);

        if (Plan::query()->doesntExist()) {
            foreach (self::examplePlans() as $i => $plan) {
                Plan::query()->create($plan + ['sort_order' => $i + 1]);
            }
            $this->warn('Example packages created (Msingi, Biashara, Pro). Change their prices and contents before selling.');
        }

        $this->info('Platform ready.');

        return self::SUCCESS;
    }

    /** Starting points only; the Software Engineer edits them. */
    public static function examplePlans(): array
    {
        $core = ['pos', 'sales', 'credit', 'customers', 'products', 'categories', 'units', 'stock', 'expenses', 'reports'];

        return [
            [
                'code' => 'msingi', 'name' => 'Msingi', 'description' => 'Duka moja: mauzo, stoo, wateja na matumizi.',
                'price_monthly' => 15000, 'price_yearly' => 150000,
                'features' => $core,
                'limits' => ['users' => 2, 'branches' => 1, 'products' => 300, 'sms_per_month' => 50],
            ],
            [
                'code' => 'biashara', 'name' => 'Biashara', 'description' => 'Matawi kadhaa, manunuzi, oda, duka la mtandaoni na mauzo kwa sauti.',
                'price_monthly' => 35000, 'price_yearly' => 350000,
                'features' => array_merge($core, ['purchases', 'suppliers', 'orders', 'online_payments', 'documents', 'transfers', 'deliveries', 'savings', 'marketing', 'faqs', 'messages', 'sms', 'mobile_app', 'online_shop', 'voice']),
                'limits' => ['users' => 8, 'branches' => 3, 'products' => 3000, 'sms_per_month' => 300],
            ],
            [
                'code' => 'pro', 'name' => 'Pro', 'description' => 'Kila kitu: uhasibu, wafanyakazi na mishahara, AI na matawi bila kikomo.',
                'price_monthly' => 75000, 'price_yearly' => 750000,
                'features' => ['*'],
                'limits' => ['users' => null, 'branches' => null, 'products' => null, 'sms_per_month' => 1000],
            ],
        ];
    }
}
