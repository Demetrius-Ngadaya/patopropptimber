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
            $this->warn('Packages created: Starter, Msingi, Biashara (most popular), Professional, Premium. Check their contents before selling.');
        }

        $this->info('Platform ready.');

        return self::SUCCESS;
    }

    /**
     * The packages SHEZONI sells. Prices are per month; a year costs ten months.
     * Starting contents only: the Software Engineer can change them at any time.
     */
    public static function examplePlans(): array
    {
        $starter = ['pos', 'sales', 'products', 'categories', 'units', 'stock', 'customers', 'expenses', 'reports'];
        $msingi = array_merge($starter, ['credit', 'suppliers', 'purchases']);
        $biashara = array_merge($msingi, [
            'orders', 'online_payments', 'documents', 'transfers', 'deliveries', 'savings', 'marketing', 'faqs',
            'visitors', 'mobile_app', 'messages', 'sms', 'allocations', 'purchase_plans', 'login_slides',
            'online_shop', 'voice',
        ]);
        $professional = array_merge($biashara, [
            'accounting', 'employees', 'departments', 'attendance', 'leave', 'payroll', 'hr', 'shifts', 'whatsapp',
        ]);

        $plan = fn (string $code, string $name, int $price, string $description, array $features, array $limits, bool $recommended = false) => [
            'code' => $code, 'name' => $name, 'description' => $description,
            'price_monthly' => $price, 'price_yearly' => $price * 10,
            'features' => $features, 'limits' => $limits, 'is_recommended' => $recommended,
        ];

        return [
            $plan('starter', 'Starter', 10000, 'Kuanza: mauzo, bidhaa, stoo, wateja na matumizi kwa mtumiaji mmoja.',
                $starter, ['users' => 1, 'branches' => 1, 'products' => 100, 'sms_per_month' => 20]),
            $plan('msingi', 'Msingi', 15000, 'Duka moja: pamoja na mauzo ya mkopo, wasambazaji na manunuzi.',
                $msingi, ['users' => 2, 'branches' => 1, 'products' => 300, 'sms_per_month' => 50]),
            $plan('biashara', 'Biashara', 35000, 'Matawi kadhaa, oda, duka la mtandaoni, malipo kwa simu na kuuza kwa sauti.',
                $biashara, ['users' => 8, 'branches' => 3, 'products' => 3000, 'sms_per_month' => 300], true),
            $plan('professional', 'Professional', 55000, 'Pamoja na uhasibu kamili, wafanyakazi, mahudhurio na mishahara.',
                $professional, ['users' => 20, 'branches' => 10, 'products' => 20000, 'sms_per_month' => 700]),
            $plan('premium', 'Premium', 75000, 'Kila kitu, pamoja na AI na uwakala, bila kikomo cha watumiaji, matawi wala bidhaa.',
                ['*'], ['users' => null, 'branches' => null, 'products' => null, 'sms_per_month' => 1500]),
        ];
    }
}
