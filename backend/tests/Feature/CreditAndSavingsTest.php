<?php

namespace Tests\Feature;

use App\Models\Crm\Customer;
use App\Models\Crm\SavingsAccount;
use App\Models\Sales\Sale;
use Tests\TestCase;

class CreditAndSavingsTest extends TestCase
{
    // ------------------------------------------------------------ credit sales

    public function test_walk_in_credit_sale_requires_phone_address_and_due_date(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(10, ['selling_price' => 10000]);

        // No due date -> refused.
        $this->postJson('/api/v1/admin/pos/checkout', [
            'new_customer' => ['name' => 'Juma Ali', 'phone' => '0712345678', 'address' => 'Sinza, Dar es Salaam'],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [],
        ])->assertStatus(422)->assertJsonPath('code', 'DUE_DATE_REQUIRED');

        // No address -> validation error.
        $this->postJson('/api/v1/admin/pos/checkout', [
            'new_customer' => ['name' => 'Juma Ali', 'phone' => '0712345678'],
            'due_date' => now()->addWeek()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('new_customer.address');

        $due = now()->addWeeks(2)->toDateString();
        $res = $this->postJson('/api/v1/admin/pos/checkout', [
            'new_customer' => ['name' => 'Juma Ali', 'phone' => '0712345678', 'address' => 'Sinza, Dar es Salaam'],
            'due_date' => $due,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
        ])->assertCreated();

        $this->assertTrue($res->json('data.is_credit'));
        $this->assertSame('15000.00', $res->json('data.credit_amount'));
        $customer = Customer::where('phone', 'like', '%712345678')->firstOrFail();
        $this->assertSame('Sinza, Dar es Salaam', $customer->address);

        // The same phone again re-uses the registered customer.
        $this->postJson('/api/v1/admin/pos/checkout', [
            'new_customer' => ['name' => 'Juma', 'phone' => '0712345678', 'address' => 'Sinza'],
            'due_date' => $due,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [],
        ])->assertCreated()->assertJsonPath('data.customer_id', $customer->id);
    }

    public function test_partial_then_full_payment_keeps_full_history(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(10, ['selling_price' => 10000]);
        $customer = Customer::factory()->create(['allow_credit' => true, 'credit_limit' => 0, 'phone' => '0755000111', 'address' => 'Arusha']);

        $saleId = $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customer->id,
            'due_date' => now()->addMonth()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [],
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/v1/admin/credit-sales?status=outstanding')->assertOk()
            ->assertJsonPath('summary.outstanding', 30000);

        // Partial
        $this->postJson("/api/v1/admin/credit-sales/{$saleId}/payments", ['mode' => 'partial', 'amount' => 12000, 'method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.summary.remaining_balance', 18000)
            ->assertJsonPath('data.summary.status', 'partially_paid');

        // More than owed is refused, never silently accepted.
        $this->postJson("/api/v1/admin/credit-sales/{$saleId}/payments", ['mode' => 'partial', 'amount' => 50000, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('code', 'OVERPAYMENT');

        // Mobile money needs a reference.
        $this->postJson("/api/v1/admin/credit-sales/{$saleId}/payments", ['mode' => 'full', 'method' => 'mobile_money'])
            ->assertStatus(422)->assertJsonValidationErrors('reference');

        // Full
        $this->postJson("/api/v1/admin/credit-sales/{$saleId}/payments", ['mode' => 'full', 'method' => 'mobile_money', 'reference' => 'MP123'])
            ->assertCreated()
            ->assertJsonPath('data.summary.remaining_balance', 0)
            ->assertJsonPath('data.summary.status', 'fully_paid');

        $show = $this->getJson("/api/v1/admin/credit-sales/{$saleId}")->assertOk();
        $history = $show->json('data.payments');
        $this->assertCount(2, $history);
        $this->assertSame([30000.0, 18000.0], [(float) $history[0]['balance_before'], (float) $history[0]['balance_after']]);
        $this->assertSame([18000.0, 0.0], [(float) $history[1]['balance_before'], (float) $history[1]['balance_after']]);
        $this->assertSame('0755000111', $history[1]['customer_phone']);
        $this->assertEquals(30000, $show->json('data.summary.original_amount'));

        $this->assertSame(0.0, (float) $customer->fresh()->balance);
        $this->assertSame(0.0, $this->ledgerBalance('receivable'));
        $this->assertLedgerBalanced();
    }

    public function test_repayment_date_can_be_rescheduled_with_reason(): void
    {
        $this->actingAsStaff();
        $product = $this->productWithStock(5, ['selling_price' => 1000]);
        $customer = Customer::factory()->create(['allow_credit' => true]);
        $saleId = $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customer->id, 'due_date' => now()->addDay()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [],
        ])->assertCreated()->json('data.id');

        $new = now()->addMonth()->toDateString();
        $this->putJson("/api/v1/admin/credit-sales/{$saleId}/due-date", ['due_date' => $new, 'reason' => 'Customer travelling'])
            ->assertOk()->assertJsonPath('data.expected_repayment_date', $new);
        $this->assertDatabaseHas('audit_logs', ['event' => 'credit.rescheduled']);
    }

    // ----------------------------------------------------------------- savings

    public function test_open_savings_with_new_customer_and_deposit(): void
    {
        $this->actingAsStaff('cashier');

        $accountId = $this->postJson('/api/v1/admin/savings', [
            'customer' => ['name' => 'Mama Neema', 'phone' => '0688111222', 'address' => 'Mwanza'],
            'initial_deposit' => 20000, 'method' => 'cash',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/savings/{$accountId}/deposits", ['amount' => 15000, 'method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.balance_before', '20000.00')
            ->assertJsonPath('data.balance_after', '35000.00');

        $account = SavingsAccount::findOrFail($accountId);
        $this->assertSame('35000.00', $account->balance);
        $this->assertSame('35000.00', $account->total_deposited);
        $this->assertSame(-35000.0, $this->ledgerBalance('customer_savings'));
        $this->assertLedgerBalanced();
    }

    public function test_purchase_from_savings_and_overflow_becomes_credit_debt(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(20, ['selling_price' => 10000]);
        $accountId = $this->postJson('/api/v1/admin/savings', [
            'customer' => ['name' => 'Baraka', 'phone' => '0677000333', 'address' => 'Dodoma'],
            'initial_deposit' => 25000, 'method' => 'cash',
        ])->assertCreated()->json('data.id');
        $customerId = SavingsAccount::findOrFail($accountId)->customer_id;
        Customer::whereKey($customerId)->update(['allow_credit' => true]);

        // 1) Within savings: 20,000 from 25,000.
        $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customerId,
            'use_savings' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [],
        ])->assertCreated()->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame('5000.00', SavingsAccount::find($accountId)->balance);

        // 2) Exceeds savings: 30,000 with 5,000 left -> 5,000 deducted, 25,000 owed on credit.
        $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customerId,
            'use_savings' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [],
        ])->assertStatus(422)->assertJsonPath('code', 'DUE_DATE_REQUIRED');

        $saleId = $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customerId,
            'use_savings' => true,
            'due_date' => now()->addWeeks(2)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [],
        ])->assertCreated()->assertJsonPath('data.credit_amount', '25000.00')->json('data.id');

        $account = SavingsAccount::find($accountId);
        $this->assertSame('0.00', $account->balance);
        $this->assertSame('25000.00', $account->total_spent);
        $this->assertDatabaseHas('savings_transactions', ['sale_id' => $saleId, 'type' => 'purchase', 'amount' => 5000, 'purchase_amount' => 30000, 'debt_amount' => 25000, 'balance_after' => 0]);

        // The debt shows up in credit sales and can be repaid there.
        $this->getJson('/api/v1/admin/credit-sales?customer_id='.$customerId)->assertOk()->assertJsonPath('summary.outstanding', 25000);
        $this->postJson("/api/v1/admin/credit-sales/{$saleId}/payments", ['mode' => 'partial', 'amount' => 10000, 'method' => 'cash'])->assertCreated();

        // One timeline with deposits, purchases from savings, debts and repayments.
        $history = $this->getJson("/api/v1/admin/customers/{$customerId}/financial-history")->assertOk();
        $this->assertEquals(25000, $history->json('data.savings.total_deposited'));
        $this->assertEquals(0, $history->json('data.savings.balance'));
        $this->assertEquals(15000, $history->json('data.credit.outstanding'));
        $this->assertEquals(-15000, $history->json('data.net_position'));
        $kinds = collect($history->json('data.timeline'))->pluck('kind')->all();
        foreach (['savings_deposit', 'savings_purchase', 'credit_sale', 'debt_payment'] as $kind) {
            $this->assertContains($kind, $kinds);
        }

        $this->assertSame(0.0, $this->ledgerBalance('customer_savings'));
        $this->assertSame(15000.0, $this->ledgerBalance('receivable'));
        $this->assertLedgerBalanced();
    }

    public function test_cancelling_a_savings_sale_returns_money_to_savings(): void
    {
        $this->actingAsStaff();
        $product = $this->productWithStock(5, ['selling_price' => 4000]);
        $accountId = $this->postJson('/api/v1/admin/savings', [
            'customer' => ['name' => 'Rehema', 'phone' => '0699111000', 'address' => 'Moshi'],
            'initial_deposit' => 10000, 'method' => 'cash',
        ])->json('data.id');
        $customerId = SavingsAccount::find($accountId)->customer_id;

        $saleId = $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customerId,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'savings', 'amount' => 4000]],
        ])->assertCreated()->json('data.id');
        $this->assertSame('6000.00', SavingsAccount::find($accountId)->balance);

        $this->postJson("/api/v1/admin/sales/{$saleId}/cancel", ['reason' => 'Wrong item'])->assertOk();
        $this->assertSame('10000.00', SavingsAccount::find($accountId)->balance);
        $this->assertSame(Sale::STATUS_CANCELLED, Sale::find($saleId)->status);
        $this->assertLedgerBalanced();
    }

    public function test_withdraw_reverse_deposit_and_close_rules(): void
    {
        $this->actingAsStaff();
        $accountId = $this->postJson('/api/v1/admin/savings', [
            'customer' => ['name' => 'Saidi', 'phone' => '0622000999', 'address' => 'Tanga'],
            'initial_deposit' => 5000, 'method' => 'cash',
        ])->json('data.id');
        $depositId = $this->postJson("/api/v1/admin/savings/{$accountId}/deposits", ['amount' => 3000, 'method' => 'cash'])->json('data.id');

        $this->postJson("/api/v1/admin/savings/{$accountId}/withdrawals", ['amount' => 9000, 'method' => 'cash', 'notes' => 'School fees'])
            ->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_SAVINGS');

        $this->postJson("/api/v1/admin/savings/{$accountId}/transactions/{$depositId}/reverse", ['reason' => 'Entered twice'])
            ->assertCreated()->assertJsonPath('data.balance_after', '5000.00');
        $this->postJson("/api/v1/admin/savings/{$accountId}/transactions/{$depositId}/reverse", ['reason' => 'again'])
            ->assertStatus(422)->assertJsonPath('code', 'ALREADY_REVERSED');

        $this->putJson("/api/v1/admin/savings/{$accountId}/status", ['status' => 'closed', 'reason' => 'Customer left'])
            ->assertStatus(422)->assertJsonPath('code', 'BALANCE_NOT_ZERO');

        $this->postJson("/api/v1/admin/savings/{$accountId}/withdrawals", ['amount' => 5000, 'method' => 'cash', 'notes' => 'Closing'])->assertCreated();
        $this->putJson("/api/v1/admin/savings/{$accountId}/status", ['status' => 'closed', 'reason' => 'Customer left'])->assertOk();

        $this->postJson("/api/v1/admin/savings/{$accountId}/deposits", ['amount' => 100, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('code', 'SAVINGS_INACTIVE');

        $account = SavingsAccount::find($accountId);
        $this->assertSame('5000.00', $account->total_deposited);
        $this->assertSame('5000.00', $account->total_withdrawn);
        $this->assertSame(0.0, $this->ledgerBalance('customer_savings'));
        $this->assertLedgerBalanced();
    }

    public function test_cashier_cannot_withdraw_or_manage_savings(): void
    {
        $this->actingAsStaff('cashier');
        $accountId = $this->postJson('/api/v1/admin/savings', [
            'customer' => ['name' => 'Asha', 'phone' => '0655000444', 'address' => 'Mbeya'],
            'initial_deposit' => 5000, 'method' => 'cash',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/savings/{$accountId}/withdrawals", ['amount' => 1000, 'method' => 'cash', 'notes' => 'x'])->assertForbidden();
        $this->putJson("/api/v1/admin/savings/{$accountId}/status", ['status' => 'frozen', 'reason' => 'test'])->assertForbidden();
    }
}
