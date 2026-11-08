<?php

namespace Tests\Feature;

use App\Models\Core\Branch;
use App\Services\Catalog\BranchPrices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchPricesTest extends TestCase
{
    use RefreshDatabase;

    private function branch(string $name = 'Morogoro Branch'): Branch
    {
        return Branch::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 3)), 'is_main' => false, 'is_active' => true]);
    }

    public function test_a_branch_sells_at_its_own_price_and_other_branches_keep_the_product_price(): void
    {
        $town = $this->branch();
        $product = $this->productWithStock(10, ['selling_price' => 3500, 'cost_price' => 1700]);
        $this->productWithStock(0); // another product without branch prices
        app(\App\Services\Inventory\StockService::class)->increase($product, $town->id, 10, 2700, 'opening', $product);

        $this->actingAsStaff('admin');
        $this->putJson("/api/v1/admin/products/{$product->id}/branch-prices", [
            'branch_id' => $town->id, 'selling_price' => 5000, 'cost_price' => 2700,
        ])->assertOk()->assertJsonPath('message', 'Branch prices saved.');

        // The till at the far branch charges its own price ...
        $cashier = $this->actingAsStaff('cashier', $town);
        $sale = $this->withHeader('X-Branch-Id', (string) $town->id)->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ])->assertCreated();
        $this->assertSame('10000.00', $sale->json('data.total'));

        // ... and the head office still sells at the product's price.
        $this->flushHeaders();
        $this->actingAsStaff('cashier');
        $main = $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 7000]],
        ])->assertCreated();
        $this->assertSame('7000.00', $main->json('data.total'));

        // Saving the product elsewhere never copies the branch price onto it.
        $this->assertSame('3500.00', $product->fresh()->selling_price);
    }

    public function test_product_list_shows_stock_values_and_totals_with_branch_prices(): void
    {
        $town = $this->branch();
        $product = $this->productWithStock(10, ['selling_price' => 3500, 'cost_price' => 1700], null, 1700);
        app(\App\Services\Inventory\StockService::class)->increase($product, $town->id, 4, 2700, 'opening', $product);
        app(BranchPrices::class)->set($product, $town->id, ['selling_price' => 5000, 'cost_price' => 2700]);

        $this->actingAsStaff('admin');
        // All branches: 10 × 3500 + 4 × 5000, and 10 × 1700 + 4 × 2700.
        $all = $this->withHeader('X-Branch-Id', 'all')->getJson('/api/v1/admin/products')->assertOk();
        $row = collect($all->json('data'))->firstWhere('id', $product->id);
        $this->assertEquals(55000, $row['stock_retail_value']);
        $this->assertEquals(27800, $row['stock_cost_value']);
        $this->assertEquals(55000, $all->json('summary.retail_value'));
        $this->assertEquals(27200, $all->json('summary.expected_profit'));

        // One branch: its own prices are the ones shown.
        $one = $this->withHeader('X-Branch-Id', (string) $town->id)->getJson('/api/v1/admin/products')->assertOk();
        $row = collect($one->json('data'))->firstWhere('id', $product->id);
        $this->assertSame('5000.00', $row['selling_price']);
        $this->assertEquals(20000, $row['stock_retail_value']);

        $show = $this->getJson("/api/v1/admin/products/{$product->id}")->assertOk();
        $townPrices = collect($show->json('data.branch_prices'))->firstWhere('branch_id', $town->id);
        $this->assertEquals(5000, $townPrices['effective']['selling_price']);
    }

    public function test_only_staff_allowed_to_change_prices_can_set_branch_prices(): void
    {
        $town = $this->branch();
        $product = $this->productWithStock(1);
        $this->actingAsStaff('cashier');
        $this->putJson("/api/v1/admin/products/{$product->id}/branch-prices", ['branch_id' => $town->id, 'selling_price' => 1])->assertForbidden();
    }

    public function test_list_pages_accept_large_page_sizes_for_the_all_choice(): void
    {
        $this->actingAsStaff('admin');
        $this->getJson('/api/v1/admin/products?per_page=5000')->assertOk()->assertJsonPath('meta.per_page', 5000);
    }

    public function test_report_downloads_the_shown_page_or_everything_and_totals_every_number_column(): void
    {
        foreach (range(1, 7) as $i) {
            $this->productWithStock($i, ['name' => "Mbao {$i}", 'selling_price' => 1000, 'cost_price' => 600], null, 600);
        }
        $this->actingAsStaff('admin');

        $view = $this->getJson('/api/v1/admin/reports/stock?per_page=5')->assertOk();
        $this->assertCount(5, $view->json('data'));
        $this->assertEquals(28, $view->json('report.totals.quantity'));          // 1+2+...+7
        $this->assertEquals(28000, $view->json('report.totals.retail_value'));
        $this->assertEquals(11200, $view->json('report.totals.expected_profit'));

        $page = $this->get('/api/v1/admin/reports/stock?export=xlsx&scope=page&page=2&per_page=5');
        $page->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $page->headers->get('Content-Type'));
        $this->get('/api/v1/admin/reports/stock?export=pdf')->assertOk();
    }
}
