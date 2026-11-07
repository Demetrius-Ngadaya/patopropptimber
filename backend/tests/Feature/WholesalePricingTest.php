<?php

namespace Tests\Feature;

use App\Models\Catalog\ProductUnit;
use App\Models\Crm\Customer;
use App\Services\Core\SettingsService;
use Tests\TestCase;

class WholesalePricingTest extends TestCase
{
    public function test_admin_manages_packs_of_a_product(): void
    {
        $this->actingAsStaff();
        $product = $this->productWithStock(0, ['selling_price' => 1000, 'min_selling_price' => 0]);

        $id = $this->postJson("/api/v1/admin/products/{$product->id}/units", [
            'name' => 'Carton (24)', 'factor' => 24, 'barcode' => 'CTN-001', 'retail_price' => 22000, 'wholesale_price' => 20000,
        ])->assertCreated()->assertJsonPath('success', true)->json('data.id');

        $this->putJson("/api/v1/admin/products/{$product->id}/units/{$id}", [
            'name' => 'Carton (24)', 'factor' => 24, 'retail_price' => 23000, 'wholesale_price' => 21000,
        ])->assertOk()->assertJsonPath('data.retail_price', '23000.00');

        $this->postJson("/api/v1/admin/products/{$product->id}/units", ['name' => 'Bad', 'factor' => 1, 'retail_price' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('factor');

        $this->deleteJson("/api/v1/admin/products/{$product->id}/units/{$id}")->assertOk();
        $this->assertSoftDeleted('product_units', ['id' => $id]);
        $this->postJson("/api/v1/admin/products/{$product->id}/units/{$id}/restore")->assertOk();
        $this->assertNotSoftDeleted('product_units', ['id' => $id]);
    }

    public function test_scanning_a_carton_barcode_finds_the_product_and_pack(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(48);
        $pack = ProductUnit::create(['product_id' => $product->id, 'name' => 'Carton (24)', 'factor' => 24, 'barcode' => 'CTN-777', 'retail_price' => 30000]);

        $this->getJson('/api/v1/admin/products/lookup?code=CTN-777')->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.matched_unit_id', $pack->id);
    }

    public function test_selling_a_pack_deducts_base_units_and_uses_pack_price(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(100, ['selling_price' => 1000, 'min_selling_price' => 0]);
        $pack = ProductUnit::create(['product_id' => $product->id, 'name' => 'Carton (24)', 'factor' => 24, 'retail_price' => 22000]);

        $res = $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [
                ['product_id' => $product->id, 'product_unit_id' => $pack->id, 'quantity' => 2],
                ['product_id' => $product->id, 'quantity' => 3], // same product sold loose is allowed
            ],
            'payments' => [['method' => 'cash', 'amount' => 47000]],
        ])->assertCreated();

        $this->assertSame('47000.00', $res->json('data.total'));
        $this->assertSame(49.0, $this->onHand($product)); // 100 - 48 - 3
        $this->assertDatabaseHas('sale_items', ['product_unit_id' => $pack->id, 'quantity' => 48, 'unit_quantity' => 2, 'unit_name' => 'Carton (24)']);
        $this->assertLedgerBalanced();
    }

    public function test_wholesale_customer_and_quantity_break_get_wholesale_price(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(100, ['selling_price' => 1000, 'wholesale_price' => 800, 'wholesale_min_qty' => 12, 'min_selling_price' => 0]);
        $trader = Customer::factory()->create(['customer_type' => 'wholesale']);

        // Wholesale customer: wholesale price even for one piece.
        $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $trader->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 800]],
        ])->assertCreated()->assertJsonPath('data.total', '800.00')->assertJsonPath('data.price_level', 'wholesale');

        // Walk-in buying 12+ pieces: automatic wholesale (setting on).
        app(SettingsService::class)->update(['auto_wholesale_by_quantity' => true]);
        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 12]],
            'payments' => [['method' => 'cash', 'amount' => 9600]],
        ])->assertCreated()->assertJsonPath('data.total', '9600.00');

        // Retail walk-in buying a few: retail price.
        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 2000]],
        ])->assertCreated()->assertJsonPath('data.total', '2000.00');
    }

    public function test_cashier_without_permission_cannot_force_wholesale(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(10, ['selling_price' => 1000, 'wholesale_price' => 800]);

        $this->postJson('/api/v1/admin/pos/checkout', [
            'price_level' => 'wholesale',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 800]],
        ])->assertStatus(403);
    }

    public function test_quote_matches_checkout_total(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(100, ['selling_price' => 1000, 'wholesale_price' => 800, 'wholesale_min_qty' => 0, 'min_selling_price' => 0]);
        $pack = ProductUnit::create(['product_id' => $product->id, 'name' => 'Dozen', 'factor' => 12, 'retail_price' => 11000, 'wholesale_price' => 9000]);
        $trader = Customer::factory()->create(['customer_type' => 'wholesale']);
        $cart = ['customer_id' => $trader->id, 'items' => [['product_id' => $product->id, 'product_unit_id' => $pack->id, 'quantity' => 2], ['product_id' => $product->id, 'quantity' => 3]]];

        $quote = $this->postJson('/api/v1/admin/pos/quote', $cart)->assertOk()->json('data');
        $this->assertEquals(20400, $quote['total']); // 2 x 9,000 + 3 x 800
        $this->assertSame('wholesale', $quote['lines'][0]['price_level']);

        $sale = $this->postJson('/api/v1/admin/pos/checkout', $cart + ['payments' => [['method' => 'cash', 'amount' => $quote['total']]]])->assertCreated();
        $this->assertSame('20400.00', $sale->json('data.total'));
        $this->assertSame('paid', $sale->json('data.payment_status'));
    }
}
