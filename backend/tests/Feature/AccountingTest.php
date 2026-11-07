<?php

namespace Tests\Feature;

use App\Models\Crm\Customer;
use App\Models\Finance\Account;
use App\Models\Finance\JournalEntry;
use App\Services\Finance\LedgerService;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    private function acc(string $key): Account
    {
        return app(LedgerService::class)->account($key);
    }

    public function test_sales_and_expenses_flow_into_statements(): void
    {
        $this->actingAsStaff();
        $product = $this->productWithStock(10, ['selling_price' => 5000, 'cost_price' => 3000], null, 3000);

        $this->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])->assertCreated();
        $this->postJson('/api/v1/admin/expenses', [
            'title' => 'Shop rent', 'amount' => 3000, 'payment_method' => 'cash', 'expense_date' => now()->toDateString(),
        ])->assertCreated();

        $pnl = $this->getJson('/api/v1/admin/accounting/reports/profit-and-loss?date_from='.now()->startOfYear()->toDateString())->assertOk();
        $this->assertEquals(20000, $pnl->json('data.totals.revenue'));
        $this->assertEquals(12000, $pnl->json('data.totals.cost_of_sales'));
        $this->assertEquals(8000, $pnl->json('data.totals.gross_profit'));
        $this->assertEquals(5000, $pnl->json('data.totals.net_profit'));

        $bs = $this->getJson('/api/v1/admin/accounting/reports/balance-sheet')->assertOk();
        $this->assertTrue($bs->json('data.balanced'));

        $tb = $this->getJson('/api/v1/admin/accounting/reports/trial-balance')->assertOk();
        $this->assertTrue($tb->json('data.balanced'));

        $gl = $this->getJson('/api/v1/admin/accounting/accounts/'.$this->acc('cash')->id.'/ledger?date_from='.now()->startOfYear()->toDateString())->assertOk();
        $this->assertEquals(17000, $gl->json('data.closing_balance'));

        $cf = $this->getJson('/api/v1/admin/accounting/reports/cash-flow?date_from='.now()->startOfYear()->toDateString())->assertOk();
        $this->assertEquals(17000, $cf->json('data.totals.operating'));
        $this->assertEquals(17000, $cf->json('data.closing_cash'));
    }

    public function test_manual_journals_must_balance_and_respect_system_accounts(): void
    {
        $this->actingAsStaff('accountant');
        $rent = Account::where('code', '6200')->firstOrFail();
        $cash = $this->acc('cash');

        $this->postJson('/api/v1/admin/accounting/journals', [
            'entry_date' => now()->toDateString(), 'description' => 'Bad',
            'lines' => [['account_id' => $rent->id, 'debit' => 100], ['account_id' => $cash->id, 'credit' => 90]],
        ])->assertStatus(422)->assertJsonPath('code', 'UNBALANCED_JOURNAL');

        $this->postJson('/api/v1/admin/accounting/journals', [
            'entry_date' => now()->toDateString(), 'description' => 'Sneaky',
            'lines' => [['account_id' => $this->acc('receivable')->id, 'debit' => 100], ['account_id' => $cash->id, 'credit' => 100]],
        ])->assertStatus(422)->assertJsonPath('code', 'MANUAL_NOT_ALLOWED');

        $id = $this->postJson('/api/v1/admin/accounting/journals', [
            'entry_date' => now()->toDateString(), 'description' => 'Rent accrual',
            'lines' => [['account_id' => $rent->id, 'debit' => 100], ['account_id' => $cash->id, 'credit' => 100]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/accounting/journals/{$id}/reverse", ['reason' => 'Posted twice'])->assertCreated();
        $this->assertSame(JournalEntry::REVERSED, JournalEntry::find($id)->status);
        $this->postJson("/api/v1/admin/accounting/journals/{$id}/reverse", ['reason' => 'again'])->assertStatus(422);
        $this->assertSame(0.0, $this->ledgerBalance('cash'));

        // Automatic entries are corrected through their documents, not by an accountant's reversal.
        $product = $this->productWithStock(1, ['selling_price' => 100]);
        $this->actingAsStaff('cashier');
        $this->postJson('/api/v1/admin/pos/checkout', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 100]]])->assertCreated();
        $this->actingAsStaff('accountant');
        $saleEntry = JournalEntry::where('event', 'sale')->firstOrFail();
        $this->postJson("/api/v1/admin/accounting/journals/{$saleEntry->id}/reverse", ['reason' => 'x x x'])->assertStatus(422)->assertJsonPath('code', 'AUTOMATIC_ENTRY');
        $this->assertLedgerBalanced();
    }

    public function test_quick_entries_and_cash_flow_classification(): void
    {
        $this->actingAsStaff();
        $bank = $this->acc('bank');
        $cash = $this->acc('cash');

        $this->postJson('/api/v1/admin/accounting/journals/quick', ['type' => 'capital', 'amount' => 1000000, 'account_id' => $bank->id, 'entry_date' => now()->toDateString()])->assertCreated();
        $this->postJson('/api/v1/admin/accounting/journals/quick', ['type' => 'transfer', 'amount' => 200000, 'from_account_id' => $bank->id, 'to_account_id' => $cash->id, 'entry_date' => now()->toDateString()])->assertCreated();
        $this->postJson('/api/v1/admin/accounting/journals/quick', ['type' => 'drawings', 'amount' => 50000, 'account_id' => $cash->id, 'entry_date' => now()->toDateString()])->assertCreated();
        $this->postJson('/api/v1/admin/accounting/journals/quick', ['type' => 'capital', 'amount' => 5, 'account_id' => $this->acc('sales')->id, 'entry_date' => now()->toDateString()])
            ->assertStatus(422)->assertJsonPath('code', 'NOT_MONEY_ACCOUNT');

        $this->assertSame(800000.0, $this->ledgerBalance('bank'));
        $this->assertSame(150000.0, $this->ledgerBalance('cash'));

        $cf = $this->getJson('/api/v1/admin/accounting/reports/cash-flow?date_from='.now()->startOfMonth()->toDateString())->assertOk();
        $this->assertEquals(950000, $cf->json('data.totals.financing'));
        $this->assertEquals(0, $cf->json('data.totals.operating'));
        $this->assertEquals(950000, $cf->json('data.closing_cash'));
        $this->assertTrue($this->getJson('/api/v1/admin/accounting/reports/balance-sheet')->json('data.balanced'));
    }

    public function test_closing_a_year_locks_it_and_moves_profit_to_retained_earnings(): void
    {
        $this->actingAsStaff();
        $last = now()->subYear();
        $yearId = $this->postJson('/api/v1/admin/accounting/fiscal-years', [
            'name' => 'FY '.$last->year, 'start_date' => $last->copy()->startOfYear()->toDateString(), 'end_date' => $last->copy()->endOfYear()->toDateString(),
        ])->assertCreated()->json('data.id');

        $other = Account::where('code', '4300')->firstOrFail();
        $this->postJson('/api/v1/admin/accounting/journals', [
            'entry_date' => $last->copy()->month(6)->toDateString(), 'description' => 'Commission',
            'lines' => [['account_id' => $this->acc('cash')->id, 'debit' => 70000], ['account_id' => $other->id, 'credit' => 70000]],
        ])->assertCreated();

        $this->postJson("/api/v1/admin/accounting/fiscal-years/{$yearId}/close")->assertOk();

        // Locked
        $this->postJson('/api/v1/admin/accounting/journals', [
            'entry_date' => $last->copy()->month(7)->toDateString(), 'description' => 'Late',
            'lines' => [['account_id' => $this->acc('cash')->id, 'debit' => 1], ['account_id' => $other->id, 'credit' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');

        // The closed year's P&L still shows its result; retained earnings now hold it.
        $pnl = $this->getJson('/api/v1/admin/accounting/reports/profit-and-loss?date_from='.$last->copy()->startOfYear()->toDateString().'&date_to='.$last->copy()->endOfYear()->toDateString());
        $this->assertEquals(70000, $pnl->json('data.totals.net_profit'));
        $this->assertSame(-70000.0, $this->ledgerBalance('retained_earnings'));
        $bs = $this->getJson('/api/v1/admin/accounting/reports/balance-sheet')->assertOk();
        $this->assertTrue($bs->json('data.balanced'));

        $this->postJson("/api/v1/admin/accounting/fiscal-years/{$yearId}/reopen", ['reason' => 'Audit adjustment'])->assertOk();
        $this->assertSame(0.0, $this->ledgerBalance('retained_earnings'));
        $this->assertLedgerBalanced();
    }

    public function test_fixed_asset_depreciation_and_disposal(): void
    {
        $this->actingAsStaff();
        $equipment = Account::where('code', '1710')->firstOrFail();
        $acquired = now()->subMonths(3)->startOfMonth()->addDays(9);

        $id = $this->postJson('/api/v1/admin/accounting/fixed-assets', [
            'name' => 'Fridge', 'asset_account_id' => $equipment->id, 'acquired_on' => $acquired->toDateString(),
            'cost' => 1200000, 'salvage_value' => 0, 'useful_life_months' => 12, 'funding' => 'cash',
        ])->assertCreated()->json('data.id');

        $period = now()->subMonth()->format('Y-m');
        $this->postJson('/api/v1/admin/accounting/fixed-assets/depreciate', ['period' => $period])
            ->assertOk()->assertJsonPath('data.months', 2)->assertJsonPath('data.amount', 200000);
        // Running again does nothing new.
        $this->postJson('/api/v1/admin/accounting/fixed-assets/depreciate', ['period' => $period])->assertStatus(422);

        $this->postJson("/api/v1/admin/accounting/fixed-assets/{$id}/dispose", [
            'disposed_on' => now()->toDateString(), 'amount' => 900000, 'method' => 'cash', 'reason' => 'Sold',
        ])->assertOk()->assertJsonPath('data.gain_or_loss', -100000);

        // cost 1.2M, depreciated 200k (no depreciation in the disposal month), sold 900k -> loss 100k
        $this->assertDatabaseHas('fixed_assets', ['id' => $id, 'status' => 'disposed', 'accumulated_depreciation' => 200000]);
        $this->assertSame(100000.0, round(app(LedgerService::class)->movement(Account::where('system_key', 'general_expense')->value('id')), 2));
        $this->assertSame(0.0, round(app(LedgerService::class)->movement($equipment->id), 2));
        $this->assertLedgerBalanced();
    }

    public function test_bank_reconciliation_requires_zero_difference(): void
    {
        $this->actingAsStaff();
        $bank = $this->acc('bank');
        $this->postJson('/api/v1/admin/accounting/journals/quick', ['type' => 'capital', 'amount' => 500000, 'account_id' => $bank->id, 'entry_date' => now()->toDateString()])->assertCreated();
        $this->postJson('/api/v1/admin/accounting/journals/quick', ['type' => 'drawings', 'amount' => 100000, 'account_id' => $bank->id, 'entry_date' => now()->toDateString()])->assertCreated();

        $recId = $this->postJson('/api/v1/admin/accounting/reconciliations', [
            'account_id' => $bank->id, 'statement_date' => now()->toDateString(), 'statement_balance' => 500000,
        ])->assertCreated()->json('data.id');

        $lines = $this->getJson("/api/v1/admin/accounting/reconciliations/{$recId}")->assertOk()->json('data.lines');
        $this->assertCount(2, $lines);
        $deposit = collect($lines)->firstWhere('debit', '500000.00');

        $this->postJson("/api/v1/admin/accounting/reconciliations/{$recId}/complete")->assertStatus(422)->assertJsonPath('code', 'NOT_RECONCILED');
        $this->postJson("/api/v1/admin/accounting/reconciliations/{$recId}/lines", ['line_ids' => [$deposit['id']], 'cleared' => true])
            ->assertOk()->assertJsonPath('data.difference', '0.00');
        $this->postJson("/api/v1/admin/accounting/reconciliations/{$recId}/complete")->assertOk();
    }

    public function test_aged_receivables_buckets_overdue_debts(): void
    {
        $this->actingAsStaff();
        $product = $this->productWithStock(10, ['selling_price' => 1000]);
        $customer = Customer::factory()->create(['allow_credit' => true]);
        $this->postJson('/api/v1/admin/pos/checkout', [
            'customer_id' => $customer->id, 'due_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]], 'payments' => [],
        ])->assertCreated();

        $res = $this->getJson('/api/v1/admin/accounting/reports/aged-receivables?as_of='.now()->addDays(45)->toDateString())->assertOk();
        $this->assertEquals(2000, $res->json('data.totals.31_60'));
        $this->assertSame($customer->name, $res->json('data.rows.0.name'));
    }
}
