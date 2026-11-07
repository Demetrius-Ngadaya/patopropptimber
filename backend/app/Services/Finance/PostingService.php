<?php

namespace App\Services\Finance;

use App\Models\Catalog\Product;
use App\Models\Crm\SavingsTransaction;
use App\Models\Finance\Account;
use App\Models\Finance\Expense;
use App\Models\Inventory\Allocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Transfer;
use App\Models\Purchasing\Purchase;
use App\Models\Purchasing\PurchaseReturn;
use App\Models\Purchasing\SupplierPayment;
use App\Models\Sales\Payment;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleReturn;
use App\Services\Core\SettingsService;
use App\Support\Finance\ChartOfAccounts;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns business events into journal entries, so the books are always up to
 * date without anyone typing journals. Each method knows the double entry for
 * one kind of event; LedgerService guarantees balance and closed periods.
 */
class PostingService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SettingsService $settings,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->bool('accounting_auto_post');
    }

    /** Money account that receives / pays a payment method (admin can override in settings). */
    public function moneyAccount(string $method): Account
    {
        // Payments by phone through the gateway can land in their own account (the gateway wallet).
        if ($method === 'online' && ($code = $this->settings->get('account_code_online'))) {
            $account = Account::query()->where('code', $code)->postable()->first();
            if ($account) {
                return $account;
            }
        }
        $key = ChartOfAccounts::paymentMethodAccounts()[$method] ?? 'cash';
        if (in_array($key, ['cash', 'bank', 'mobile_money'], true)) {
            $code = $this->settings->get("account_code_{$key}");
            if ($code) {
                $account = Account::query()->where('code', $code)->postable()->first();
                if ($account) {
                    return $account;
                }
            }
        }

        return $this->ledger->account($key);
    }

    // ------------------------------------------------------------- sales

    public function sale(Sale $sale): void
    {
        if (! $this->enabled() || (float) $sale->total <= 0) {
            return;
        }
        $sale->loadMissing('payments');
        $lines = [];

        foreach ($sale->payments->where('type', 'sale')->groupBy('method') as $method => $payments) {
            $lines[] = ['account' => $this->moneyAccount($method), 'debit' => $payments->sum(fn ($p) => (float) $p->amount), 'customer_id' => $sale->customer_id, 'memo' => $method];
        }
        if ((float) $sale->balance > 0) {
            $lines[] = [
                'account' => $sale->channel === 'allocation' ? 'seller_receivable' : 'receivable',
                'debit' => (float) $sale->balance,
                'customer_id' => $sale->customer_id,
                'memo' => $sale->channel === 'allocation' ? __('Seller shortage') : __('Credit sale'),
            ];
        }

        $tax = (float) $sale->tax;
        $delivery = (float) $sale->delivery_fee;
        $lines[] = ['account' => 'sales', 'credit' => (float) $sale->total - $tax - $delivery, 'customer_id' => $sale->customer_id];
        $lines[] = ['account' => 'vat_payable', 'credit' => $tax];
        $lines[] = ['account' => 'delivery_income', 'credit' => $delivery];
        $lines[] = ['account' => 'cogs', 'debit' => (float) $sale->cost_total, 'memo' => __('Cost of goods sold')];
        $lines[] = ['account' => 'inventory', 'credit' => (float) $sale->cost_total, 'memo' => __('Cost of goods sold')];

        $this->ledger->post([
            'date' => $sale->sold_at,
            'description' => __('Sale :number', ['number' => $sale->number]),
            'event' => 'sale',
            'source' => $sale,
            'branch_id' => $sale->branch_id,
            'reference' => $sale->number,
        ], $lines);
    }

    /** Cancelling a sale reverses everything posted for it (sale, debt payments, returns). */
    public function saleCancelled(Sale $sale, string $reason): void
    {
        if ($this->enabled()) {
            $this->ledger->reverseSource($sale, $reason);
        }
    }

    public function saleReturn(SaleReturn $return, Sale $sale, float $restockedCost): void
    {
        if (! $this->enabled()) {
            return;
        }
        $value = (float) $return->total;
        $base = max(0.01, (float) $sale->total - (float) $sale->delivery_fee);
        $taxShare = round((float) $sale->tax * $value / $base, 2);

        $this->ledger->post([
            'date' => $return->created_at ?? now(),
            'description' => __('Return :return on sale :sale', ['return' => $return->number, 'sale' => $sale->number]),
            'event' => 'sale_return',
            'source' => $sale,
            'branch_id' => $sale->branch_id,
            'reference' => $return->number,
        ], [
            ['account' => 'sales_returns', 'debit' => $value - $taxShare, 'customer_id' => $sale->customer_id],
            ['account' => 'vat_payable', 'debit' => $taxShare],
            ['account' => $sale->channel === 'allocation' ? 'seller_receivable' : 'receivable', 'credit' => (float) $return->credit_amount, 'customer_id' => $sale->customer_id],
            ['account' => $this->moneyAccount($return->refund_method ?: 'cash'), 'credit' => (float) $return->refund_amount, 'customer_id' => $sale->customer_id],
            ['account' => 'inventory', 'debit' => $restockedCost, 'memo' => __('Returned to stock')],
            ['account' => 'cogs', 'credit' => $restockedCost, 'memo' => __('Returned to stock')],
        ]);
    }

    /** A customer paying debt on a credit sale. */
    public function debtPayment(Payment $payment, Sale $sale): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->ledger->post([
            'date' => $payment->paid_at,
            'description' => __('Debt payment :payment on sale :sale', ['payment' => $payment->number, 'sale' => $sale->number]),
            'event' => 'debt_payment',
            'source' => $sale,
            'branch_id' => $payment->branch_id,
            'reference' => $payment->number,
        ], [
            ['account' => $this->moneyAccount($payment->method), 'debit' => (float) $payment->amount, 'customer_id' => $sale->customer_id],
            ['account' => $sale->channel === 'allocation' ? 'seller_receivable' : 'receivable', 'credit' => (float) $payment->amount, 'customer_id' => $sale->customer_id],
        ]);
    }

    // ----------------------------------------------------------- savings

    public function savingsDeposit(SavingsTransaction $tx): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->ledger->post([
            'date' => $tx->transacted_at,
            'description' => __('Savings deposit :number', ['number' => $tx->number]),
            'event' => 'savings_deposit',
            'source' => $tx->account,
            'branch_id' => $tx->branch_id,
            'reference' => $tx->number,
        ], [
            ['account' => $this->moneyAccount($tx->method ?: 'cash'), 'debit' => (float) $tx->amount, 'customer_id' => $tx->customer_id],
            ['account' => 'customer_savings', 'credit' => (float) $tx->amount, 'customer_id' => $tx->customer_id],
        ]);
    }

    public function savingsDepositReversed(SavingsTransaction $tx): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->ledger->post([
            'date' => $tx->transacted_at,
            'description' => __('Savings deposit :number reversed', ['number' => $tx->reference]),
            'event' => 'savings_deposit_reversed',
            'source' => $tx->account,
            'branch_id' => $tx->branch_id,
            'reference' => $tx->number,
        ], [
            ['account' => 'customer_savings', 'debit' => (float) $tx->amount, 'customer_id' => $tx->customer_id],
            ['account' => $this->moneyAccount($tx->method ?: 'cash'), 'credit' => (float) $tx->amount, 'customer_id' => $tx->customer_id],
        ]);
    }

    public function savingsWithdrawal(SavingsTransaction $tx): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->ledger->post([
            'date' => $tx->transacted_at,
            'description' => __('Savings withdrawal :number', ['number' => $tx->number]),
            'event' => 'savings_withdrawal',
            'source' => $tx->account,
            'branch_id' => $tx->branch_id,
            'reference' => $tx->number,
        ], [
            ['account' => 'customer_savings', 'debit' => (float) $tx->amount, 'customer_id' => $tx->customer_id],
            ['account' => $this->moneyAccount($tx->method ?: 'cash'), 'credit' => (float) $tx->amount, 'customer_id' => $tx->customer_id],
        ]);
    }

    // --------------------------------------------------------- purchases

    public function purchase(Purchase $purchase, string $paymentMethod): void
    {
        if (! $this->enabled()) {
            return;
        }
        $extras = (float) $purchase->total - (float) $purchase->subtotal;
        $this->ledger->post([
            'date' => $purchase->purchase_date,
            'description' => __('Purchase :number', ['number' => $purchase->number]),
            'event' => 'purchase',
            'source' => $purchase,
            'branch_id' => $purchase->branch_id,
            'reference' => $purchase->supplier_invoice_no ?: $purchase->number,
        ], [
            ['account' => 'inventory', 'debit' => (float) $purchase->total],
            ['account' => $this->moneyAccount($paymentMethod), 'credit' => (float) $purchase->amount_paid, 'supplier_id' => $purchase->supplier_id],
            ['account' => 'payable', 'credit' => (float) $purchase->balance, 'supplier_id' => $purchase->supplier_id],
            ['account' => 'cash', 'credit' => $extras, 'memo' => __('Transport, tax and handling')],
        ]);
    }

    public function supplierPayment(SupplierPayment $payment): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->ledger->post([
            'date' => $payment->paid_on,
            'description' => __('Payment :number to supplier', ['number' => $payment->number]),
            'event' => 'supplier_payment',
            'source' => $payment,
            'branch_id' => $payment->branch_id,
            'reference' => $payment->reference ?: $payment->number,
        ], [
            ['account' => 'payable', 'debit' => (float) $payment->amount, 'supplier_id' => $payment->supplier_id],
            ['account' => $this->moneyAccount($payment->method), 'credit' => (float) $payment->amount, 'supplier_id' => $payment->supplier_id],
        ]);
    }

    /** @param float $landedValue inventory value removed (landed cost of the returned goods) */
    public function purchaseReturn(PurchaseReturn $return, Purchase $purchase, float $landedValue): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->ledger->post([
            'date' => $return->created_at ?? now(),
            'description' => __('Return :return to supplier on :purchase', ['return' => $return->number, 'purchase' => $purchase->number]),
            'event' => 'purchase_return',
            'source' => $purchase,
            'branch_id' => $purchase->branch_id,
            'reference' => $return->number,
        ], [
            // Cash purchases (no supplier account) are refunded in cash.
            ['account' => $purchase->supplier_id ? 'payable' : 'cash', 'debit' => (float) $return->total, 'supplier_id' => $purchase->supplier_id],
            ['account' => 'inventory_loss', 'debit' => max(0, $landedValue - (float) $return->total), 'memo' => __('Transport and handling not recovered')],
            ['account' => 'inventory', 'credit' => $landedValue],
            ['account' => 'inventory_gain', 'credit' => max(0, (float) $return->total - $landedValue)],
        ]);
    }

    // ---------------------------------------------------------- expenses

    public function expense(Expense $expense): void
    {
        if (! $this->enabled()) {
            return;
        }
        $expense->loadMissing('category.account');
        $account = $expense->category?->account && ! $expense->category->account->is_header ? $expense->category->account : $this->ledger->account('general_expense');

        $this->ledger->post([
            'date' => $expense->expense_date,
            'description' => __('Expense :number: :title', ['number' => $expense->number, 'title' => $expense->title]),
            'event' => 'expense',
            'source' => $expense,
            'branch_id' => $expense->branch_id,
            'reference' => $expense->reference ?: $expense->number,
        ], [
            ['account' => $account, 'debit' => (float) $expense->amount, 'memo' => $expense->title],
            ['account' => $this->moneyAccount($expense->payment_method), 'credit' => (float) $expense->amount, 'memo' => $expense->title],
        ]);
    }

    /** Re-post after an edit: reverse what was posted, then post the new values. */
    public function expenseChanged(Expense $expense, string $reason): void
    {
        if ($this->enabled()) {
            $this->ledger->reverseSource($expense, $reason);
            if (! $expense->trashed()) {
                $this->expense($expense);
            }
        }
    }

    // --------------------------------------------------------- inventory

    public function openingStock(Product $product, int $branchId, float $value): void
    {
        if (! $this->enabled() || $value <= 0) {
            return;
        }
        $this->ledger->post([
            'description' => __('Opening stock of :product', ['product' => $product->name]),
            'event' => 'opening_stock',
            'source' => $product,
            'branch_id' => $branchId,
        ], [
            ['account' => 'inventory', 'debit' => $value],
            ['account' => 'opening_equity', 'credit' => $value],
        ]);
    }

    public function stockAdjustment(StockAdjustment $adjustment, float $value): void
    {
        if (! $this->enabled() || $value <= 0) {
            return;
        }
        $increase = (float) $adjustment->quantity > 0;
        $this->ledger->post([
            'description' => __('Stock adjustment :number: :reason', ['number' => $adjustment->number, 'reason' => $adjustment->reason]),
            'event' => 'stock_adjustment',
            'source' => $adjustment,
            'branch_id' => $adjustment->branch_id,
            'reference' => $adjustment->number,
        ], $increase ? [
            ['account' => 'inventory', 'debit' => $value],
            ['account' => 'inventory_gain', 'credit' => $value],
        ] : [
            ['account' => 'inventory_loss', 'debit' => $value],
            ['account' => 'inventory', 'credit' => $value],
        ]);
    }

    public function transferSent(Transfer $transfer): void
    {
        if (! $this->enabled()) {
            return;
        }
        $value = (float) $transfer->total_cost;
        $this->ledger->post([
            'date' => $transfer->sent_at,
            'description' => __('Transfer :number sent', ['number' => $transfer->number]),
            'event' => 'transfer_sent',
            'source' => $transfer,
            'branch_id' => $transfer->from_branch_id,
            'reference' => $transfer->number,
        ], [
            ['account' => 'goods_in_transit', 'debit' => $value, 'branch_id' => $transfer->to_branch_id],
            ['account' => 'inventory', 'credit' => $value, 'branch_id' => $transfer->from_branch_id],
        ]);
    }

    /**
     * @param  float  $receivedCost  cost value of what arrived
     * @param  float  $receivingCosts  transport etc. paid by the receiving branch (cash)
     */
    public function transferReceived(Transfer $transfer, float $receivedCost, float $receivingCosts): void
    {
        if (! $this->enabled()) {
            return;
        }
        $sentCost = (float) $transfer->total_cost;
        $this->ledger->post([
            'date' => $transfer->received_at,
            'description' => __('Transfer :number received', ['number' => $transfer->number]),
            'event' => 'transfer_received',
            'source' => $transfer,
            'branch_id' => $transfer->to_branch_id,
            'reference' => $transfer->number,
        ], [
            ['account' => 'inventory', 'debit' => $receivedCost + $receivingCosts],
            ['account' => 'inventory_loss', 'debit' => max(0, $sentCost - $receivedCost), 'memo' => __('Short on arrival')],
            ['account' => 'goods_in_transit', 'credit' => $sentCost],
            ['account' => 'cash', 'credit' => $receivingCosts, 'memo' => __('Receiving costs')],
            ['account' => 'inventory_gain', 'credit' => max(0, $receivedCost - $sentCost)],
        ]);
    }

    public function transferCancelled(Transfer $transfer, string $reason): void
    {
        if ($this->enabled()) {
            $this->ledger->reverseSource($transfer, $reason, ['transfer_sent']);
        }
    }

    public function allocationDamages(Allocation $allocation, float $value): void
    {
        if (! $this->enabled() || $value <= 0) {
            return;
        }
        $this->ledger->post([
            'description' => __('Goods damaged under allocation :number', ['number' => $allocation->number]),
            'event' => 'allocation_damage',
            'source' => $allocation,
            'branch_id' => $allocation->branch_id,
            'reference' => $allocation->number,
        ], [
            ['account' => 'inventory_loss', 'debit' => $value],
            ['account' => 'inventory', 'credit' => $value],
        ]);
    }

    /** Generic entry for modules that build their own lines (payroll, advances). */
    public function post(array $entry, array $lines, ?Model $source = null): void
    {
        if ($this->enabled()) {
            $this->ledger->post($entry + ['source' => $source], $lines);
        }
    }

    public function reverse(Model $source, string $reason, ?array $events = null): void
    {
        if ($this->enabled()) {
            $this->ledger->reverseSource($source, $reason, $events);
        }
    }
}
