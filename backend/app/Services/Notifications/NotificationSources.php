<?php

namespace App\Services\Notifications;

use App\Models\Crm\Customer;
use App\Models\Finance\Expense;
use App\Models\Inventory\ProductStock;
use App\Models\Inventory\StockBatch;
use App\Models\Sales\Payment;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleItem;
use App\Models\Sales\SalesDocument;
use App\Services\Marketing\CustomerInsights;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a custom notification can report on. Each source gives:
 *  - variables for the templates ({total_sales}, {low_count} ...)
 *  - default wording for SMS, WhatsApp and email (used when the admin leaves a template empty)
 *  - tables for the detailed email and its PDF
 */
class NotificationSources
{
    public function __construct(private readonly CustomerInsights $insights) {}

    /** @return array<string, array{label: string, permission: string, variables: array<string, string>}> */
    public static function catalogue(): array
    {
        return [
            'sales_summary' => ['label' => 'Sales summary', 'permission' => 'reports.sales', 'variables' => [
                'total_sales' => 'Net sales', 'receipts' => 'Number of sales', 'cash_collected' => 'Money received for sales',
                'credit_given' => 'Sold on credit', 'debts_collected' => 'Debts collected', 'expenses' => 'Expenses', 'top_product' => 'Best-selling product',
            ]],
            'stock_alerts' => ['label' => 'Stock alerts (low, out, expiring)', 'permission' => 'stock.alerts', 'variables' => [
                'low_count' => 'Products running low', 'out_count' => 'Products out of stock', 'expiring_count' => 'Batches expiring soon', 'expired_count' => 'Batches expired',
            ]],
            'credit_sales' => ['label' => 'Credit sales made', 'permission' => 'credit.view', 'variables' => [
                'count' => 'Credit sales', 'amount' => 'Amount given on credit', 'customers' => 'Customers',
            ]],
            'overdue_debts' => ['label' => 'Overdue customer debts', 'permission' => 'credit.view', 'variables' => [
                'customers' => 'Customers with overdue debts', 'invoices' => 'Overdue sales', 'amount' => 'Amount overdue',
            ]],
            'debt_collections' => ['label' => 'Debt collections', 'permission' => 'credit.view', 'variables' => [
                'count' => 'Payments received', 'amount' => 'Amount collected',
            ]],
            'expenses' => ['label' => 'Expenses', 'permission' => 'expenses.view', 'variables' => [
                'total' => 'Total expenses', 'count' => 'Number of expenses', 'top_category' => 'Biggest category',
            ]],
            'invoices' => ['label' => 'Invoices owing and overdue', 'permission' => 'documents.view', 'variables' => [
                'owing_count' => 'Invoices owing', 'owing_amount' => 'Amount owing', 'overdue_count' => 'Invoices overdue', 'overdue_amount' => 'Amount overdue',
            ]],
            'quotations' => ['label' => 'Quotations waiting for an answer', 'permission' => 'documents.view', 'variables' => [
                'waiting_count' => 'Quotations waiting', 'waiting_value' => 'Their value', 'accepted_count' => 'Accepted in the period', 'expiring_count' => 'Expiring in 3 days',
            ]],
            'customers' => ['label' => 'Customers (new, VIP, coming less, lost)', 'permission' => 'marketing.insights', 'variables' => [
                'new_customers' => 'New customers', 'vip_count' => 'VIP customers', 'declining_count' => 'Coming less often', 'lost_count' => 'Stopped coming',
            ]],
        ];
    }

    /** Default wording (English keys, translated into the message language). */
    public static function defaults(string $source): array
    {
        $common = ['subject' => '{business}: {name} ({period})', 'email' => "Hello,\n\nHere is {name} for {period}."];

        return match ($source) {
            'sales_summary' => $common + [
                'sms' => '{business} {period}: sales {currency} {total_sales} ({receipts}), credit {credit_given}, collected {debts_collected}, expenses {expenses}.',
                'whatsapp' => "*{business}* - {name}\n{period}\n\nSales: {currency} {total_sales} ({receipts} sales)\nOn credit: {currency} {credit_given}\nDebts collected: {currency} {debts_collected}\nExpenses: {currency} {expenses}\nBest seller: {top_product}",
            ],
            'stock_alerts' => $common + [
                'sms' => '{business}: {low_count} low, {out_count} out of stock, {expiring_count} expiring, {expired_count} expired.',
                'whatsapp' => "*{business}* - {name}\n\nRunning low: {low_count}\nOut of stock: {out_count}\nExpiring soon: {expiring_count}\nExpired: {expired_count}",
            ],
            'credit_sales' => $common + [
                'sms' => '{business} {period}: {count} credit sales, {currency} {amount} to {customers} customers.',
                'whatsapp' => "*{business}* - {name}\n{period}\n\nCredit sales: {count}\nAmount: {currency} {amount}\nCustomers: {customers}",
            ],
            'overdue_debts' => $common + [
                'sms' => '{business}: {customers} customers owe {currency} {amount} past the due date.',
                'whatsapp' => "*{business}* - {name}\n\nCustomers: {customers}\nOverdue sales: {invoices}\nAmount overdue: {currency} {amount}",
            ],
            'debt_collections' => $common + [
                'sms' => '{business} {period}: {count} debt payments, {currency} {amount} collected.',
                'whatsapp' => "*{business}* - {name}\n{period}\n\nPayments: {count}\nCollected: {currency} {amount}",
            ],
            'expenses' => $common + [
                'sms' => '{business} {period}: expenses {currency} {total} ({count}), most on {top_category}.',
                'whatsapp' => "*{business}* - {name}\n{period}\n\nExpenses: {currency} {total}\nNumber: {count}\nBiggest category: {top_category}",
            ],
            'invoices' => $common + [
                'sms' => '{business}: {owing_count} invoices owing ({currency} {owing_amount}), {overdue_count} overdue ({currency} {overdue_amount}).',
                'whatsapp' => "*{business}* - {name}\n\nOwing: {owing_count} ({currency} {owing_amount})\nOverdue: {overdue_count} ({currency} {overdue_amount})",
            ],
            'quotations' => $common + [
                'sms' => '{business}: {waiting_count} quotations waiting ({currency} {waiting_value}), {expiring_count} expire in 3 days.',
                'whatsapp' => "*{business}* - {name}\n\nWaiting for an answer: {waiting_count} ({currency} {waiting_value})\nAccepted {period}: {accepted_count}\nExpiring in 3 days: {expiring_count}",
            ],
            'customers' => $common + [
                'sms' => '{business} {period}: {new_customers} new customers, {vip_count} VIP, {declining_count} coming less, {lost_count} stopped coming.',
                'whatsapp' => "*{business}* - {name}\n{period}\n\nNew customers: {new_customers}\nVIP: {vip_count}\nComing less often: {declining_count}\nStopped coming: {lost_count}",
            ],
            default => $common + ['sms' => '{business}: {name} ({period})', 'whatsapp' => '{business}: {name} ({period})'],
        };
    }

    /**
     * @param  array<int>|null  $branchIds
     * @return array{vars: array<string, string>, sections: array, empty: bool}
     */
    public function build(string $source, Carbon $from, Carbon $to, ?array $branchIds): array
    {
        $in = fn ($q, string $col = 'branch_id') => $branchIds === null ? $q : $q->whereIn($col, $branchIds ?: [0]);
        $n = fn ($v) => number_format((float) $v);
        $today = today();

        switch ($source) {
            case 'sales_summary':
                $sales = $in(Sale::query()->completed()->whereBetween('sold_at', [$from, $to]));
                $total = (float) (clone $sales)->sum(DB::raw('total - returned_amount'));
                $receipts = (clone $sales)->count();
                $credit = (float) (clone $sales)->where('is_credit', true)->sum('credit_amount');
                $collected = (float) $in(Payment::query()->where('type', 'debt')->whereBetween('paid_at', [$from, $to]))->sum('amount');
                $paidForSales = (float) $in(Payment::query()->where('type', 'sale')->whereBetween('paid_at', [$from, $to]))->sum('amount');
                $expenses = (float) $in(Expense::query()->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]))->sum('amount');
                $top = SaleItem::query()->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.status', 'completed')
                    ->whereBetween('sales.sold_at', [$from, $to])->when($branchIds !== null, fn ($q) => $q->whereIn('sales.branch_id', $branchIds ?: [0]))
                    ->groupBy('sale_items.product_name')->selectRaw('sale_items.product_name as name, SUM(sale_items.line_total) as amount, SUM(sale_items.quantity) as qty')
                    ->orderByDesc('amount')->limit(10)->get();

                return [
                    'vars' => ['total_sales' => $n($total), 'receipts' => (string) $receipts, 'cash_collected' => $n($paidForSales), 'credit_given' => $n($credit), 'debts_collected' => $n($collected), 'expenses' => $n($expenses), 'top_product' => (string) ($top->first()->name ?? '-')],
                    'sections' => [['heading' => __('Best sellers'), 'columns' => [__('Product'), __('Quantity'), __('Amount')], 'numeric' => [1, 2], 'rows' => $top->map(fn ($t) => [$t->name, 0 + round((float) $t->qty, 3), $n($t->amount)])->all()]],
                    'empty' => $receipts === 0 && $expenses == 0.0,
                ];

            case 'stock_alerts':
                $levels = $in(ProductStock::query(), 'product_stocks.branch_id')->join('products', 'products.id', '=', 'product_stocks.product_id')
                    ->whereNull('products.deleted_at')->where('products.is_active', true)->where('products.is_service', false)
                    ->where(fn ($q) => $q->where('product_stocks.quantity', '<=', 0)->orWhere(fn ($w) => $w->where('products.reorder_level', '>', 0)->whereColumn('product_stocks.quantity', '<=', 'products.reorder_level')))
                    ->with('branch:id,name')->orderBy('products.name')->limit(300)->get(['product_stocks.branch_id', 'product_stocks.quantity', 'products.name', 'products.reorder_level']);
                $batches = $in(StockBatch::query(), 'stock_batches.branch_id')->join('products', 'products.id', '=', 'stock_batches.product_id')
                    ->where('stock_batches.quantity_remaining', '>', 0)->whereNotNull('stock_batches.expiry_date')
                    ->where('stock_batches.expiry_date', '<=', $today->copy()->addDays(30)->toDateString())->orderBy('stock_batches.expiry_date')->limit(300)
                    ->get(['stock_batches.expiry_date', 'stock_batches.quantity_remaining', 'products.name']);
                $out = $levels->filter(fn ($r) => (float) $r->quantity <= 0);
                $expired = $batches->filter(fn ($b) => $b->expiry_date->lt($today));

                return [
                    'vars' => ['low_count' => (string) ($levels->count() - $out->count()), 'out_count' => (string) $out->count(), 'expiring_count' => (string) ($batches->count() - $expired->count()), 'expired_count' => (string) $expired->count()],
                    'sections' => [
                        ['heading' => __('Low and out of stock'), 'columns' => [__('Product'), __('Branch'), __('Left'), __('Reorder level')], 'numeric' => [2, 3], 'rows' => $levels->map(fn ($r) => [$r->name, $r->branch?->name, 0 + (float) $r->quantity, 0 + (float) $r->reorder_level])->all()],
                        ['heading' => __('Expired and expiring'), 'columns' => [__('Product'), __('Expiry date'), __('Quantity')], 'numeric' => [2], 'rows' => $batches->map(fn ($b) => [$b->name, $b->expiry_date->format('d/m/Y'), 0 + (float) $b->quantity_remaining])->all()],
                    ],
                    'empty' => $levels->isEmpty() && $batches->isEmpty(),
                ];

            case 'credit_sales':
                $rows = $in(Sale::query()->completed()->where('is_credit', true)->whereBetween('sold_at', [$from, $to]))->with('customer:id,name')->orderByDesc('sold_at')->limit(300)->get();

                return [
                    'vars' => ['count' => (string) $rows->count(), 'amount' => $n($rows->sum('credit_amount')), 'customers' => (string) $rows->pluck('customer_id')->unique()->count()],
                    'sections' => [['heading' => __('Credit sales'), 'columns' => [__('Sale'), __('Customer'), __('On credit'), __('Due date')], 'numeric' => [2], 'rows' => $rows->map(fn ($s) => [$s->number, $s->customer?->name, $n($s->credit_amount), $s->due_date?->format('d/m/Y')])->all()]],
                    'empty' => $rows->isEmpty(),
                ];

            case 'overdue_debts':
                $rows = $in(Sale::query()->completed()->where('balance', '>', 0)->whereNotNull('customer_id')->whereDate('due_date', '<', $today))->with('customer:id,name,phone')->get();
                $by = $rows->groupBy('customer_id')->map(fn ($g) => [$g->first()->customer?->name, $g->first()->customer?->phone, $g->count(), $n($g->sum('balance'))])->sortByDesc(fn ($r) => $r[3])->values();

                return [
                    'vars' => ['customers' => (string) $by->count(), 'invoices' => (string) $rows->count(), 'amount' => $n($rows->sum('balance'))],
                    'sections' => [['heading' => __('By customer'), 'columns' => [__('Customer'), __('Phone'), __('Sales'), __('Amount')], 'numeric' => [2, 3], 'rows' => $by->take(300)->all()]],
                    'empty' => $rows->isEmpty(),
                ];

            case 'debt_collections':
                $rows = $in(Payment::query()->where('type', 'debt')->whereBetween('paid_at', [$from, $to]))->with('customer:id,name')->orderByDesc('paid_at')->limit(300)->get();

                return [
                    'vars' => ['count' => (string) $rows->count(), 'amount' => $n($rows->sum('amount'))],
                    'sections' => [['heading' => __('Payments received'), 'columns' => [__('Date'), __('Customer'), __('Method'), __('Amount')], 'numeric' => [3], 'rows' => $rows->map(fn ($p) => [$p->paid_at?->format('d/m/Y H:i'), $p->customer?->name, __(ucfirst(str_replace('_', ' ', (string) $p->method))), $n($p->amount)])->all()]],
                    'empty' => $rows->isEmpty(),
                ];

            case 'expenses':
                $rows = $in(Expense::query()->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]))->with('category:id,name')->get();
                $byCat = $rows->groupBy(fn ($e) => $e->category?->name ?? '-')->map(fn ($g, $k) => [$k, $g->count(), $n($g->sum('amount'))])->sortByDesc(fn ($r) => (float) str_replace(',', '', $r[2]))->values();

                return [
                    'vars' => ['total' => $n($rows->sum('amount')), 'count' => (string) $rows->count(), 'top_category' => (string) ($byCat->first()[0] ?? '-')],
                    'sections' => [['heading' => __('By category'), 'columns' => [__('Category'), __('Count'), __('Amount')], 'numeric' => [1, 2], 'rows' => $byCat->all()]],
                    'empty' => $rows->isEmpty(),
                ];

            case 'invoices':
                $base = fn () => $in(SalesDocument::query()->where('type', SalesDocument::INVOICE))->with('sale:id,status,balance,amount_paid');
                $owing = $base()->stage('owing')->get();
                $overdue = $base()->stage('overdue')->orderBy('due_date')->get();

                return [
                    'vars' => ['owing_count' => (string) $owing->count(), 'owing_amount' => $n($owing->sum('balance')), 'overdue_count' => (string) $overdue->count(), 'overdue_amount' => $n($overdue->sum('balance'))],
                    'sections' => [['heading' => __('Overdue invoices'), 'columns' => [__('Invoice'), __('Customer'), __('Due date'), __('Balance')], 'numeric' => [3], 'rows' => $overdue->take(300)->map(fn ($d) => [$d->number, $d->customer_name, $d->due_date?->format('d/m/Y'), $n($d->balance)])->all()]],
                    'empty' => $owing->isEmpty() && $overdue->isEmpty(),
                ];

            case 'quotations':
                $waiting = $in(SalesDocument::query()->where('type', SalesDocument::QUOTATION))->stage('sent')->orderBy('due_date')->get();
                $accepted = $in(SalesDocument::query()->where('type', SalesDocument::QUOTATION))->whereBetween('accepted_at', [$from, $to])->count();
                $expiring = $waiting->filter(fn ($d) => $d->due_date && $d->due_date->lte($today->copy()->addDays(3)));

                return [
                    'vars' => ['waiting_count' => (string) $waiting->count(), 'waiting_value' => $n($waiting->sum('total')), 'accepted_count' => (string) $accepted, 'expiring_count' => (string) $expiring->count()],
                    'sections' => [['heading' => __('Waiting for an answer'), 'columns' => [__('Quotation'), __('Customer'), __('Valid until'), __('Amount')], 'numeric' => [3], 'rows' => $waiting->take(300)->map(fn ($d) => [$d->number, $d->customer_name, $d->due_date?->format('d/m/Y'), $n($d->total)])->all()]],
                    'empty' => $waiting->isEmpty() && $accepted === 0,
                ];

            case 'customers':
                $metrics = $this->insights->metrics($branchIds);
                $count = fn (string $seg) => $metrics->filter(fn ($m) => in_array($seg, $m['segments'], true))->count();
                $new = Customer::query()->whereBetween('created_at', [$from, $to])->count();
                $names = Customer::query()->whereIn('id', $metrics->keys())->pluck('name', 'id');
                $lost = $metrics->filter(fn ($m) => in_array('lost', $m['segments'], true) || in_array('declining', $m['segments'], true))->sortByDesc('lifetime_spend')->take(100);

                return [
                    'vars' => ['new_customers' => (string) $new, 'vip_count' => (string) $count('vip'), 'declining_count' => (string) $count('declining'), 'lost_count' => (string) $count('lost')],
                    'sections' => [['heading' => __('Valuable customers coming less or not at all'), 'columns' => [__('Customer'), __('Last purchase'), __('Spent (12 months)')], 'numeric' => [2], 'rows' => $lost->map(fn ($m) => [$names[$m['customer_id']] ?? '-', Carbon::parse($m['last_purchase_at'])->format('d/m/Y'), $n($m['spend_365'])])->values()->all()]],
                    'empty' => false,
                ];
        }

        return ['vars' => [], 'sections' => [], 'empty' => true];
    }
}
