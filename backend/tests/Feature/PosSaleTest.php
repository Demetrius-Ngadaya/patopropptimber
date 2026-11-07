<?php

namespace Tests\Feature;

use App\Models\Crm\Customer;
use App\Models\Inventory\StockBatch;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use App\Services\Inventory\StockService;
use Tests\TestCase;

class PosSaleTest extends TestCase
{
    public function test_cash_sale_deducts_stock_records_payment_and_change(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(10, ['selling_price' => 1500]);

        $response = $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
        ])->assertCreated();

        $this->assertSame('4500.00', $response->json('data.total'));
        $this->assertSame('500.00', $response->json('data.change_given'));
        $this->assertSame('paid', $response->json('data.payment_status'));
        $this->assertSame(7.0, $this->onHand($product));
        $this->assertDatabaseHas('payments', ['sale_id' => $response->json('data.id'), 'amount' => 4500, 'method' => 'cash']);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'type' => 'sale', 'quantity' => -3]);
    }

    public function test_sale_uses_first_expiry_first_out_and_blocks_expired_stock(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(5, ['track_expiry' => true], null, 1000, now()->addMonths(6)->toDateString());
        app(StockService::class)->increase($product, $this->mainBranch()->id, 5, 1000, 'purchase', null, ['expiry_date' => now()->addMonth()->toDateString()]);
        app(StockService::class)->increase($product, $this->mainBranch()->id, 5, 1000, 'purchase', null, ['expiry_date' => now()->subDay()->toDateString()]);

        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 6]],
            'payments' => [['method' => 'cash', 'amount' => 9000]],
        ])->assertCreated();

        $soonest = StockBatch::where('product_id', $product->id)->where('expiry_date', '>=', now()->addDays(20))->where('expiry_date', '<=', now()->addDays(40))->first();
        $this->assertSame('0.000', $soonest->quantity_remaining); // first-expiry batch used first
        $this->assertSame(9.0, $this->onHand($product));

        // 4 good units left + 5 expired: asking for 6 must fail.
        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 6]],
            'payments' => [['method' => 'cash', 'amount' => 9000]],
        ])->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    }

    public function test_cannot_sell_more_than_stock(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(2);

        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 4500]],
        ])->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        $this->assertSame(2.0, $this->onHand($product));
        $this->assertSame(0, Sale::count());
    }

    public function test_decimal_quantity_only_for_weighed_products(): void
    {
        $this->actingAsStaff('cashier');
        $meat = $this->productWithStock(10, ['allow_decimal' => true, 'selling_price' => 14000]);
        $soda = $this->productWithStock(10);

        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $meat->id, 'quantity' => 0.75]],
            'payments' => [['method' => 'cash', 'amount' => 10500]],
        ])->assertCreated()->assertJsonPath('data.total', '10500.00');

        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $soda->id, 'quantity' => 1.5]],
            'payments' => [['method' => 'cash', 'amount' => 3000]],
        ])->assertStatus(422);
    }

    public function test_cashier_without_permission_cannot_change_price(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(5);

        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertForbidden();
    }

    public function test_mobile_money_payment_needs_a_reference(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(5);

        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'mobile_money', 'provider' => 'M-Pesa', 'amount' => 1500]],
        ])->assertStatus(422)->assertJsonValidationErrors('payments.0.reference');
    }

    public function test_credit_sale_respects_customer_permission_and_limit(): void
    {
        app(SettingsService::class)->update(['credit_requires_approval' => true]);
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(10);
        $noCredit = Customer::factory()->create();
        $limited = Customer::factory()->withCredit(2000)->create();

        $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $noCredit->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [],
            'due_date' => now()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('code', 'CREDIT_NOT_ALLOWED');

        $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $limited->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 500]],
            'due_date' => now()->addWeek()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.balance', '1000.00');
        $this->assertSame('1000.00', $limited->fresh()->balance);

        // 1000 owed + 1500 new > 2000 limit
        $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $limited->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [],
            'due_date' => now()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('code', 'CREDIT_LIMIT');
    }

    public function test_debt_payment_is_applied_to_oldest_sale_first(): void
    {
        $this->actingAsStaff('super-admin');
        $product = $this->productWithStock(10);
        $customer = Customer::factory()->withCredit()->create();

        foreach (range(1, 2) as $i) {
            $this->postJson('/api/v1/admin/pos/checkout', [
                'customer_id' => $customer->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [],
                'due_date' => now()->addWeek()->toDateString(),
            ])->assertCreated();
        }
        $this->assertSame('3000.00', $customer->fresh()->balance);

        $this->postJson("/api/v1/admin/customers/{$customer->id}/payments", [
            'amount' => 2000, 'method' => 'mobile_money', 'provider' => 'M-Pesa', 'reference' => 'QWE123',
        ])->assertCreated();

        $sales = Sale::orderBy('id')->get();
        $this->assertSame('paid', $sales[0]->payment_status);
        $this->assertSame('1000.00', $sales[1]->balance);
        $this->assertSame('1000.00', $customer->fresh()->balance);
    }

    public function test_return_restocks_good_items_and_refunds(): void
    {
        $this->actingAsStaff('super-admin');
        $product = $this->productWithStock(10);

        $sale = $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'payments' => [['method' => 'cash', 'amount' => 6000]],
        ])->json('data');

        $this->postJson("/api/v1/admin/sales/{$sale['id']}/returns", [
            'items' => [
                ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2, 'condition' => 'restock'],
            ],
            'reason' => 'Customer changed mind',
        ])->assertCreated()->assertJsonPath('data.refund_amount', '3000.00');

        $this->assertSame(8.0, $this->onHand($product));
        $this->assertSame('3000.00', Sale::find($sale['id'])->returned_amount);
    }

    public function test_cancelling_a_sale_returns_stock_and_clears_debt(): void
    {
        $this->actingAsStaff('super-admin');
        $product = $this->productWithStock(10);
        $customer = Customer::factory()->withCredit()->create();

        $sale = $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [],
            'due_date' => now()->addWeek()->toDateString(),
        ])->json('data');

        $this->postJson("/api/v1/admin/sales/{$sale['id']}/cancel", ['reason' => 'Wrong customer'])->assertOk();

        $this->assertSame(10.0, $this->onHand($product));
        $this->assertSame('0.00', $customer->fresh()->balance);
        $this->assertSame('cancelled', Sale::find($sale['id'])->status);
    }
}
