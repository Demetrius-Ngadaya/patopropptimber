<?php

namespace Tests\Feature;

use App\Models\Core\AppNotification;
use App\Models\Core\SmsLog;
use App\Models\Purchasing\Purchase;
use App\Models\Purchasing\Supplier;
use App\Services\Core\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PurchasePlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mauzo.sms.enabled' => true, 'mauzo.sms.token' => 't']);
        Http::fake(['*' => Http::response(['status' => 'success'])]);
        app(SettingsService::class)->update(['messaging_channels' => ['sms', 'email'], 'notify_sms_numbers' => '0712000111', 'message_locale' => 'en']);
        Carbon::setTestNow('2026-06-01 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_plan_moves_through_its_stages_and_credit_deliveries_get_reminders(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-1', 'name' => 'Bakhresa Wholesale', 'phone' => '0754000000', 'is_active' => true]);
        $sugar = $this->productWithStock(0, ['name' => 'Sukari 50kg']);
        $oil = $this->productWithStock(0, ['name' => 'Mafuta 20L']);

        $this->actingAsStaff('storekeeper');
        $plan = $this->postJson('/api/v1/admin/purchase-plans', [
            'supplier_id' => $supplier->id, 'title' => 'June stock', 'expected_date' => '2026-06-05',
            'payment_type' => 'credit', 'credit_days' => 30, 'reminder_days_before' => 5, 'reminder_channels' => ['sms'],
            'items' => [['product_id' => $sugar->id, 'quantity' => 10, 'unit_cost' => 120000], ['product_id' => $oil->id, 'quantity' => 4, 'unit_cost' => 80000]],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.estimated_total', '1520000.00')->json('data');

        // The storekeeper prepares; someone else approves.
        $this->postJson("/api/v1/admin/purchase-plans/{$plan['id']}/submit")->assertForbidden();
        $this->actingAsStaff('accountant');
        $this->postJson("/api/v1/admin/purchase-plans/{$plan['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending');

        $this->actingAsStaff('storekeeper');
        Carbon::setTestNow('2026-06-05 10:00:00');
        $items = collect($this->getJson("/api/v1/admin/purchase-plans/{$plan['id']}")->json('data.items'))->keyBy('product_id');
        $this->postJson("/api/v1/admin/purchase-plans/{$plan['id']}/receive", [
            'purchase_date' => '2026-06-05', 'items' => [['plan_item_id' => $items[$sugar->id]['id'], 'quantity' => 11]],
        ])->assertUnprocessable()->assertJsonPath('code', 'OVER_RECEIPT');

        // First delivery: 6 bags of sugar, on credit (the storekeeper cannot record credit himself, the approved plan allows it).
        $first = $this->postJson("/api/v1/admin/purchase-plans/{$plan['id']}/receive", [
            'purchase_date' => '2026-06-05', 'supplier_invoice_no' => 'BW-881', 'items' => [['plan_item_id' => $items[$sugar->id]['id'], 'quantity' => 6]],
        ])->assertCreated()->json('data');
        $purchase = Purchase::find($first['id']);
        $this->assertEquals(720000, (float) $purchase->balance);
        $this->assertSame('2026-07-05', $purchase->due_date->toDateString());
        $this->assertSame('2026-06-30', $purchase->remind_on->toDateString());
        $this->assertSame(['sms'], $purchase->reminder_channels);
        $this->assertEquals(6, $this->onHand($sugar));
        $this->getJson("/api/v1/admin/purchase-plans/{$plan['id']}")->assertJsonPath('data.status', 'partially_received');

        // The rest arrives.
        $this->postJson("/api/v1/admin/purchase-plans/{$plan['id']}/receive", [
            'purchase_date' => '2026-06-05', 'items' => [['plan_item_id' => $items[$sugar->id]['id'], 'quantity' => 4], ['plan_item_id' => $items[$oil->id]['id'], 'quantity' => 4, 'unit_cost' => 82000]],
        ])->assertCreated();
        $this->getJson("/api/v1/admin/purchase-plans/{$plan['id']}")->assertJsonPath('data.status', 'received')->assertJsonPath('data.received_total', '1528000.00');
        $this->postJson("/api/v1/admin/purchase-plans/{$plan['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertEquals(1528000, (float) $supplier->fresh()->balance);

        // Nothing to remind yet; on the reminder day one message, then again on the due date.
        $this->artisan('mauzo:purchase-reminders')->assertSuccessful();
        $this->assertSame(0, SmsLog::query()->count());
        Carbon::setTestNow('2026-06-30 08:00:00');
        $this->artisan('mauzo:purchase-reminders')->assertSuccessful();
        $this->artisan('mauzo:purchase-reminders')->assertSuccessful();
        $this->assertSame(2, AppNotification::query()->where('type', 'purchase_due')->count()); // one per purchase
        $sms = SmsLog::query()->latest('id')->first();
        $this->assertNotNull($sms);
        $this->assertStringContainsString('Bakhresa Wholesale', $sms->message);
        Carbon::setTestNow('2026-07-05 08:00:00');
        $this->artisan('mauzo:purchase-reminders')->assertSuccessful();
        $this->assertSame(4, AppNotification::query()->where('type', 'purchase_due')->count());

        $this->actingAsStaff('accountant');
        $report = $this->getJson('/api/v1/admin/reports/supplier_debts')->assertOk()->json('report.summary');
        $this->assertEquals(1528000, $report['owed']);
        $this->getJson('/api/v1/admin/reports/purchase_plans?date_from=2026-06-01&date_to=2026-07-31')->assertOk()->assertJsonPath('report.summary.closed', 1);
    }

    public function test_cancel_and_reminder_on_ordinary_purchase(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-2', 'name' => 'Azam', 'is_active' => true]);
        $product = $this->productWithStock(0);
        $this->actingAsStaff();
        $plan = $this->postJson('/api/v1/admin/purchase-plans', ['payment_type' => 'cash', 'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1000]]])->json('data');
        $this->postJson("/api/v1/admin/purchase-plans/{$plan['id']}/receive", ['purchase_date' => '2026-06-01', 'items' => []])->assertUnprocessable();
        $this->postJson("/api/v1/admin/purchase-plans/{$plan['id']}/cancel", ['reason' => 'Supplier out of stock'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/admin/purchase-plans/{$plan['id']}/submit")->assertUnprocessable();

        // Goods on credit are owed to someone: a credit plan needs its supplier before approval.
        $credit = $this->postJson('/api/v1/admin/purchase-plans', ['payment_type' => 'credit', 'credit_days' => 14, 'items' => [['product_id' => $product->id, 'quantity' => 5]]])->json('data');
        $this->postJson("/api/v1/admin/purchase-plans/{$credit['id']}/submit")->assertUnprocessable();
        $this->putJson("/api/v1/admin/purchase-plans/{$credit['id']}", ['supplier_id' => $supplier->id, 'payment_type' => 'credit', 'credit_days' => 14, 'items' => [['product_id' => $product->id, 'quantity' => 5]]])->assertOk();
        $this->postJson("/api/v1/admin/purchase-plans/{$credit['id']}/submit")->assertOk();

        // A purchase on credit made directly: the reminder follows the settings, and can be moved.
        $purchase = $this->postJson('/api/v1/admin/purchases', [
            'supplier_id' => $supplier->id, 'purchase_date' => '2026-06-01', 'amount_paid' => 0, 'due_date' => '2026-06-20',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 5000]],
        ])->assertCreated()->json('data');
        $this->assertSame('2026-06-17', Purchase::find($purchase['id'])->remind_on->toDateString()); // 3 days before (setting)
        $this->putJson("/api/v1/admin/purchases/{$purchase['id']}/reminder", ['remind_on' => '2026-06-10', 'reminder_channels' => ['email', 'whatsapp']])->assertOk();
        $fresh = Purchase::find($purchase['id']);
        $this->assertSame('2026-06-10', $fresh->remind_on->toDateString());
        $this->assertSame(['email'], $fresh->reminder_channels); // WhatsApp is not switched on for this business

        $this->getJson('/api/v1/admin/purchases?payment_status=owed')->assertJsonCount(1, 'data');
    }
}
