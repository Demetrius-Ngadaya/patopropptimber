<?php

namespace Tests;

use App\Models\Catalog\Product;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Finance\JournalLine;
use App\Services\Finance\LedgerService;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Run DatabaseSeeder (permissions, roles, main branch, units) before each test. */
    protected bool $seed = true;

    protected function mainBranch(): Branch
    {
        return Branch::query()->where('code', 'MAIN')->firstOrFail();
    }

    protected function staff(string $role = 'super-admin', ?Branch $branch = null, array $attributes = []): User
    {
        return User::factory()->role($role)->create(array_merge([
            'branch_id' => ($branch ?? $this->mainBranch())->id,
        ], $attributes));
    }

    protected function actingAsStaff(string $role = 'super-admin', ?Branch $branch = null): User
    {
        $user = $this->staff($role, $branch);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function productWithStock(float $quantity, array $attributes = [], ?Branch $branch = null, float $cost = 1000, ?string $expiry = null): Product
    {
        $product = Product::factory()->create($attributes);
        if ($quantity > 0) {
            app(StockService::class)->increase($product, ($branch ?? $this->mainBranch())->id, $quantity, $cost, 'opening', $product, ['expiry_date' => $expiry]);
        }

        return $product;
    }

    protected function onHand(Product $product, ?Branch $branch = null): float
    {
        return app(StockService::class)->onHand($product->id, ($branch ?? $this->mainBranch())->id);
    }

    /** Every posted journal must balance, and so must the whole ledger. */
    protected function assertLedgerBalanced(): void
    {
        $totals = JournalLine::query()->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
        $this->assertEqualsWithDelta((float) $totals->d, (float) $totals->c, 0.001, 'The general ledger does not balance.');
    }

    /** Net balance (debit - credit) of a system account, e.g. "cash" or "receivable". */
    protected function ledgerBalance(string $systemKey): float
    {
        $account = app(LedgerService::class)->account($systemKey);
        $row = JournalLine::query()->where('account_id', $account->id)->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return round((float) $row->d - (float) $row->c, 2);
    }
}
