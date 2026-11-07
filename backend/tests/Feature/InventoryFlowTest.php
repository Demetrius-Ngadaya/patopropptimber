<?php

namespace Tests\Feature;

use App\Models\Catalog\Product;
use App\Models\Core\AuditLog;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Inventory\StockBatch;
use App\Models\Inventory\Transfer;
use App\Models\Purchasing\Supplier;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryFlowTest extends TestCase
{
    public function test_purchase_spreads_extra_costs_into_landed_cost_and_tracks_supplier_debt(): void
    {
        $this->actingAsStaff('super-admin');
        $supplier = Supplier::create(['code' => 'SUP-1', 'name' => 'Wholesaler', 'is_active' => true]);
        $a = Product::factory()->create();
        $b = Product::factory()->create();

        $response = $this->postJson('/api/v1/admin/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $a->id, 'quantity' => 10, 'unit_cost' => 1000],   // 10,000
                ['product_id' => $b->id, 'quantity' => 10, 'unit_cost' => 3000],   // 30,000
            ],
            'transport_cost' => 4000,
            'cost_allocation' => 'value',
            'amount_paid' => 15000,
        ])->assertCreated();

        // Transport split by value: A gets 1,000 (100/unit), B gets 3,000 (300/unit).
        $this->assertSame('1100.00', $a->fresh()->cost_price);
        $this->assertSame('3300.00', $b->fresh()->cost_price);
        $this->assertSame(10.0, $this->onHand($a));
        $this->assertSame('partial', $response->json('data.payment_status'));
        $this->assertSame('25000.00', $supplier->fresh()->balance);
    }

    public function test_transfer_moves_stock_only_when_received_and_keeps_expiry(): void
    {
        $main = $this->mainBranch();
        $town = Branch::factory()->create();
        $product = $this->productWithStock(20, ['track_expiry' => true], $main, 1000, now()->addMonths(3)->toDateString());

        $sender = $this->staff('storekeeper', $main);
        Sanctum::actingAs($sender);
        $transfer = $this->postJson('/api/v1/admin/transfers', [
            'to_branch_id' => $town->id,
            'type' => 'free',
            'items' => [['product_id' => $product->id, 'quantity' => 8]],
        ])->assertCreated()->json('data');

        $this->assertSame(12.0, $this->onHand($product, $main));
        $this->assertSame(0.0, $this->onHand($product, $town));

        // The sending branch cannot receive its own transfer.
        $this->postJson("/api/v1/admin/transfers/{$transfer['id']}/receive", [])->assertForbidden();

        $receiver = User::factory()->role('storekeeper')->create(['branch_id' => $town->id]);
        Sanctum::actingAs($receiver);
        $this->postJson("/api/v1/admin/transfers/{$transfer['id']}/receive", [
            'items' => [['transfer_item_id' => $transfer['items'][0]['id'], 'received_quantity' => 8]],
        ])->assertOk();

        $this->assertSame(8.0, $this->onHand($product, $town));
        $this->assertSame('received', Transfer::find($transfer['id'])->status);
        $batch = StockBatch::where('branch_id', $town->id)->where('product_id', $product->id)->first();
        $this->assertSame(now()->addMonths(3)->toDateString(), $batch->expiry_date->toDateString());
    }

    public function test_stock_count_adjustment_sets_the_quantity_and_is_logged(): void
    {
        $this->actingAsStaff('super-admin');
        $product = $this->productWithStock(10);

        $this->postJson('/api/v1/admin/stock/adjustments', [
            'product_id' => $product->id,
            'type' => 'count',
            'quantity' => 7,
            'reason' => 'Monthly stock take',
        ])->assertCreated();

        $this->assertSame(7.0, $this->onHand($product));
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'type' => 'adjustment_out', 'quantity' => -3]);
        $this->assertDatabaseHas('stock_adjustments', ['product_id' => $product->id, 'reason' => 'Monthly stock take']);
    }

    public function test_allocation_reconciliation_restocks_returns_and_records_shortage(): void
    {
        $this->actingAsStaff('super-admin');
        $seller = $this->staff('seller');
        $product = $this->productWithStock(30, ['selling_price' => 1000]);

        $allocation = $this->postJson('/api/v1/admin/allocations', [
            'seller_id' => $seller->id,
            'items' => [['product_id' => $product->id, 'quantity' => 20]],
        ])->assertCreated()->json('data');
        $this->assertSame(10.0, $this->onHand($product));

        $result = $this->postJson("/api/v1/admin/allocations/{$allocation['id']}/reconcile", [
            'items' => [['allocation_item_id' => $allocation['items'][0]['id'], 'quantity_returned' => 5, 'quantity_damaged' => 1]],
            'amount_received' => 13000,
        ])->assertOk()->json('data');

        // 14 sold x 1000 = 14,000 expected, 13,000 received.
        $this->assertSame('14000.00', $result['sold_amount']);
        $this->assertSame('1000.00', $result['shortage']);
        $this->assertSame(15.0, $this->onHand($product));
        $this->assertNotNull($result['sale_id']);
    }

    public function test_seller_sells_from_allocation_then_reconciles_the_rest(): void
    {
        $this->actingAsStaff('super-admin');
        $seller = $this->staff('seller');
        $other = $this->staff('seller');
        $product = $this->productWithStock(30, ['selling_price' => 1000]);
        $allocation = $this->postJson('/api/v1/admin/allocations', [
            'seller_id' => $seller->id,
            'items' => [['product_id' => $product->id, 'quantity' => 20]],
        ])->assertCreated()->json('data');
        $itemId = $allocation['items'][0]['id'];
        $sell = fn (float $qty, array $payments) => $this->postJson("/api/v1/admin/allocations/{$allocation['id']}/sales", [
            'items' => [['allocation_item_id' => $itemId, 'quantity' => $qty]], 'customer_name' => 'Mama Juma', 'payments' => $payments,
        ]);

        // Another seller cannot sell from it.
        Sanctum::actingAs($other);
        $sell(1, [['method' => 'cash', 'amount' => 1000]])->assertNotFound();

        Sanctum::actingAs($seller);
        // Allocation sales are paid in full: no credit.
        $sell(2, [['method' => 'cash', 'amount' => 1500]])->assertStatus(422);
        $sale = $sell(8, [['method' => 'cash', 'amount' => 10000]])->assertCreated()->json('data');
        $this->assertSame('8000.00', $sale['total']);
        $this->assertSame('2000.00', $sale['change_given']);
        $this->getJson("/api/v1/admin/sales/{$sale['id']}")->assertOk(); // the seller can open the receipt
        $sell(13, [['method' => 'cash', 'amount' => 13000]])->assertStatus(422); // only 12 left
        $this->assertSame(10.0, $this->onHand($product)); // stock left the shop when issued, not again

        // Back at the shop: 5 returned, 1 damaged, so 6 more were sold without being recorded.
        $this->actingAsStaff('super-admin');
        $this->postJson("/api/v1/admin/allocations/{$allocation['id']}/cancel", ['reason' => 'mistake'])->assertStatus(422);
        $result = $this->postJson("/api/v1/admin/allocations/{$allocation['id']}/reconcile", [
            'items' => [['allocation_item_id' => $itemId, 'quantity_returned' => 5, 'quantity_damaged' => 1]],
            'amount_received' => 6000,
        ])->assertOk()->json('data');
        $this->assertSame('14000.00', $result['sold_amount']);
        $this->assertSame('0.00', $result['shortage']);
        $this->assertSame('14.000', $result['items'][0]['quantity_sold']);
        $this->assertSame(15.0, $this->onHand($product));
    }

    public function test_product_delete_is_soft_and_restorable_with_audit_trail(): void
    {
        $this->actingAsStaff('super-admin');
        $product = Product::factory()->create();

        $this->deleteJson("/api/v1/admin/products/{$product->id}", ['reason' => 'Discontinued'])->assertOk();
        $this->assertSoftDeleted($product);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'product', 'auditable_id' => $product->id, 'event' => 'deleted']);

        $this->postJson("/api/v1/admin/products/{$product->id}/restore")->assertOk();
        $this->assertNotSoftDeleted($product);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'product', 'auditable_id' => $product->id, 'event' => 'restored']);
    }

    public function test_product_with_stock_cannot_be_deleted(): void
    {
        $this->actingAsStaff('super-admin');
        $product = $this->productWithStock(3);

        $this->deleteJson("/api/v1/admin/products/{$product->id}", ['reason' => 'test'])->assertStatus(422);
    }

    public function test_product_edit_requires_a_reason_and_records_before_and_after(): void
    {
        $this->actingAsStaff('super-admin');
        $product = Product::factory()->create(['selling_price' => 1500]);

        $payload = ['name' => $product->name, 'cost_price' => 1000, 'selling_price' => 1800];
        $this->putJson("/api/v1/admin/products/{$product->id}", $payload)->assertStatus(422)->assertJsonValidationErrors('change_reason');

        $this->putJson("/api/v1/admin/products/{$product->id}", $payload + ['change_reason' => 'Supplier price increase'])->assertOk();

        $log = AuditLog::where('auditable_type', 'product')->where('auditable_id', $product->id)->where('event', 'updated')->latest('id')->first();
        $this->assertSame('1500.00', $log->old_values['selling_price']);
        $this->assertSame('1800.00', $log->new_values['selling_price']);
        $this->assertStringContainsString('Supplier price increase', $log->description);
    }

    public function test_every_write_action_is_recorded_in_the_activity_log(): void
    {
        $user = $this->actingAsStaff('super-admin');

        $this->postJson('/api/v1/admin/expenses', [
            'title' => 'Electricity', 'amount' => 25000, 'payment_method' => 'cash', 'expense_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'method' => 'POST', 'route_name' => 'admin.expenses.store', 'status_code' => 201]);
    }
}
