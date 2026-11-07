<?php

namespace Database\Seeders;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\Unit;
use App\Models\Core\Branch;
use App\Models\Core\Role;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Purchasing\Supplier;
use App\Services\Inventory\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sample data for trying the system: php artisan db:seed --class=DemoSeeder
 * Do NOT run on a live business database.
 */
class DemoSeeder extends Seeder
{
    public function run(StockService $stock): void
    {
        $this->call(DatabaseSeeder::class);

        $main = Branch::query()->where('code', 'MAIN')->first();
        $town = Branch::query()->firstOrCreate(['code' => 'TOWN'], ['name' => 'Town Branch', 'location' => 'City centre', 'is_active' => true]);

        $cashierRole = Role::query()->where('slug', 'cashier')->first();
        User::query()->firstOrCreate(['username' => 'cashier'], [
            'type' => 'staff', 'name' => 'Demo Cashier', 'password' => 'Cashier123', 'role_id' => $cashierRole->id,
            'branch_id' => $main->id, 'is_active' => true,
        ]);

        $units = Unit::query()->pluck('id', 'short_name');
        $catalogue = [
            'Meat' => [['Beef steak', 'kg', 9000, 14000, true], ['Goat meat', 'kg', 10000, 15000, true], ['Chicken (whole)', 'pc', 9000, 13000, false]],
            'Medicines' => [['Paracetamol 500mg', 'strip', 400, 1000, false, true], ['Amoxicillin 250mg', 'strip', 1500, 3000, false, true]],
            'Hardware' => [['Cement 50kg', 'bag', 16500, 19000, false], ['Iron sheet 28G', 'pc', 18000, 23000, false], ['Wire nails 3"', 'kg', 3500, 5000, true]],
            'Drinks' => [['Mineral water 1.5L', 'btl', 700, 1000, false], ['Soda 500ml', 'btl', 600, 1000, false]],
        ];

        foreach ($catalogue as $categoryName => $items) {
            $category = Category::query()->firstOrCreate(['slug' => Str::slug($categoryName)], ['name' => $categoryName, 'show_online' => true, 'is_active' => true]);
            foreach ($items as $item) {
                [$name, $unit, $cost, $price, $decimal] = $item;
                $tracksExpiry = $item[5] ?? false;
                $product = Product::query()->firstOrCreate(['slug' => Str::slug($name)], [
                    'name' => $name, 'category_id' => $category->id, 'unit_id' => $units[$unit] ?? null,
                    'cost_price' => $cost, 'selling_price' => $price, 'reorder_level' => 10,
                    'allow_decimal' => $decimal, 'track_expiry' => $tracksExpiry, 'show_online' => true, 'is_active' => true,
                    'sku' => strtoupper(Str::substr(Str::slug($name, ''), 0, 8)).rand(10, 99),
                ]);
                foreach ([$main, $town] as $branch) {
                    if ($stock->onHand($product->id, $branch->id) <= 0) {
                        $stock->increase($product, $branch->id, rand(20, 120), $cost, 'opening', $product, [
                            'expiry_date' => $tracksExpiry ? now()->addMonths(rand(2, 18))->toDateString() : null,
                            'note' => 'Demo opening stock',
                        ]);
                    }
                }
            }
        }

        Supplier::query()->firstOrCreate(['code' => 'SUP-DEMO'], ['name' => 'Demo Wholesalers Ltd', 'phone' => '255700000001', 'payment_terms_days' => 30, 'is_active' => true]);
        Customer::query()->firstOrCreate(['code' => 'CUS-DEMO'], [
            'name' => 'Juma Hassan', 'phone' => '255712000000', 'allow_credit' => true, 'credit_limit' => 500000, 'is_active' => true, 'branch_id' => $main->id,
        ]);

        $this->command?->info('Demo data created. Extra login: cashier / Cashier123');
    }
}
