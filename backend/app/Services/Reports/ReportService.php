<?php

namespace App\Services\Reports;

use App\Exceptions\BusinessException;
use App\Models\Agency\AgencyCommission;
use App\Models\Agency\AgencyDay;
use App\Models\Catalog\Product;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Finance\Expense;
use App\Models\Inventory\Allocation;
use App\Models\Inventory\ProductStock;
use App\Models\Inventory\StockBatch;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Transfer;
use App\Models\Payments\OnlinePayment;
use App\Models\Purchasing\Purchase;
use App\Models\Purchasing\PurchasePlan;
use App\Models\Purchasing\Supplier;
use App\Models\Sales\Payment;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleReturnItem;
use App\Models\Sales\SalesDocument;
use App\Services\Catalog\BranchPrices;
use App\Services\Core\SettingsService;
use App\Support\BranchContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every report returns:
 *  title, columns (key => label/type), either `query` (paginated list) or
 *  `rows` (small aggregated collection), `totals` and optional `summary`.
 */
class ReportService
{
    /** SQL for the net (after returns) value of a sale_items row. */
    private const NET_QTY = '(sale_items.quantity - sale_items.returned_quantity)';

    private const NET_REVENUE = '(sale_items.line_total * (sale_items.quantity - sale_items.returned_quantity) / NULLIF(sale_items.quantity, 0))';

    private const NET_COST = '(sale_items.unit_cost * (sale_items.quantity - sale_items.returned_quantity))';

    public function __construct(
        private readonly BranchContext $branch,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Report catalogue for the frontend menu.
     *
     * @return array<string, array{title: string, permission: string, group: string, filters: array<string>, description: string}>
     */
    public static function catalogue(): array
    {
        $dates = ['date_from', 'date_to'];

        return [
            'sales' => ['title' => 'Sales register', 'group' => 'Sales', 'permission' => 'reports.sales', 'filters' => [...$dates, 'cashier_id', 'customer_id', 'payment_status', 'channel', 'status'], 'description' => 'Every invoice with totals, payments and balances.'],
            'sales_items' => ['title' => 'Sales by item', 'group' => 'Sales', 'permission' => 'reports.sales', 'filters' => [...$dates, 'product_id', 'category_id', 'cashier_id'], 'description' => 'Each product line sold.'],
            'payment_methods' => ['title' => 'Collections by payment method', 'group' => 'Sales', 'permission' => 'reports.sales', 'filters' => $dates, 'description' => 'Cash, mobile money, bank ... received.'],
            'returns' => ['title' => 'Returns & damaged goods', 'group' => 'Sales', 'permission' => 'reports.sales', 'filters' => [...$dates, 'condition'], 'description' => 'Goods returned by customers.'],
            'product_performance' => ['title' => 'Product performance', 'group' => 'Performance', 'permission' => 'reports.performance', 'filters' => [...$dates, 'category_id', 'sort', 'limit'], 'description' => 'Best and worst sellers, fast and slow movers.'],
            'never_sold' => ['title' => 'Products not sold', 'group' => 'Performance', 'permission' => 'reports.performance', 'filters' => [...$dates, 'category_id'], 'description' => 'Products with stock and no sales in the period.'],
            'staff_performance' => ['title' => 'Staff performance', 'group' => 'Performance', 'permission' => 'reports.performance', 'filters' => $dates, 'description' => 'Sales per cashier / seller.'],
            'stock' => ['title' => 'Stock on hand & valuation', 'group' => 'Stock', 'permission' => 'reports.stock', 'filters' => ['category_id', 'stock_status', 'search'], 'description' => 'Quantities with value at cost and at selling price.'],
            'stock_movements' => ['title' => 'Stock ledger', 'group' => 'Stock', 'permission' => 'reports.stock', 'filters' => [...$dates, 'product_id', 'movement_type', 'user_id'], 'description' => 'Every stock in / out.'],
            'expiry' => ['title' => 'Expiry report', 'group' => 'Stock', 'permission' => 'reports.stock', 'filters' => ['expiry_status', 'days', 'category_id'], 'description' => 'Expired and soon-to-expire batches.'],
            'purchases' => ['title' => 'Purchases', 'group' => 'Purchases', 'permission' => 'reports.purchases', 'filters' => [...$dates, 'supplier_id', 'payment_status'], 'description' => 'Goods received with landed costs.'],
            'supplier_debts' => ['title' => 'Credit purchases to pay', 'group' => 'Purchases', 'permission' => 'reports.purchases', 'filters' => ['supplier_id', 'due_status'], 'description' => 'Every purchase still owed: when to pay, when the reminder goes, how late.'],
            'purchase_plans' => ['title' => 'Purchase plans', 'group' => 'Purchases', 'permission' => 'reports.purchases', 'filters' => [...$dates, 'supplier_id', 'plan_status'], 'description' => 'Planned purchases by stage: ordered, received and still to come.'],
            'agency_days' => ['title' => 'Uwakala business report', 'group' => 'Uwakala', 'permission' => 'agency.reports', 'filters' => $dates, 'description' => 'Each day: cash and float at opening and closing, totals, capital in / out, shortages and excesses.'],
            'agency_commissions' => ['title' => 'Uwakala commissions', 'group' => 'Uwakala', 'permission' => 'agency.reports', 'filters' => [...$dates, 'agency_account_id'], 'description' => 'Commissions received from each provider, by month.'],
            'online_payments' => ['title' => 'Payments by phone (online)', 'group' => 'Sales', 'permission' => 'online_payments.view', 'filters' => [...$dates, 'online_status', 'online_purpose'], 'description' => 'Mobile money paid through Snippe at the till and for shop orders: fees, failures, and money not yet used for a sale.'],
            'supplier_balances' => ['title' => 'Supplier balances', 'group' => 'Purchases', 'permission' => 'reports.purchases', 'filters' => ['search'], 'description' => 'What we owe each supplier.'],
            'quotations' => ['title' => 'Quotations', 'group' => 'Quotations & invoices', 'permission' => 'reports.sales', 'filters' => [...$dates, 'customer_id', 'doc_stage'], 'description' => 'Quotations by status, value and how many became sales.'],
            'proformas' => ['title' => 'Proforma invoices', 'group' => 'Quotations & invoices', 'permission' => 'reports.sales', 'filters' => [...$dates, 'customer_id', 'doc_stage'], 'description' => 'Proformas waiting for payment, paid and closed.'],
            'invoices' => ['title' => 'Invoices', 'group' => 'Quotations & invoices', 'permission' => 'reports.sales', 'filters' => [...$dates, 'customer_id', 'doc_stage'], 'description' => 'Invoices owing, overdue, paid and closed.'],
            'invoice_aging' => ['title' => 'Unpaid invoices (aging)', 'group' => 'Quotations & invoices', 'permission' => 'reports.credit', 'filters' => ['customer_id'], 'description' => 'What is still owed on invoices, by how late it is.'],
            'debtors' => ['title' => 'Debtors (aging)', 'group' => 'Credit', 'permission' => 'reports.credit', 'filters' => ['search'], 'description' => 'Customer debts by age.'],
            'credit_sales' => ['title' => 'Credit sales', 'group' => 'Credit', 'permission' => 'reports.credit', 'filters' => [...$dates, 'credit_status', 'customer_id'], 'description' => 'Unpaid, overdue and paid credit sales.'],
            'debt_payments' => ['title' => 'Debt collections', 'group' => 'Credit', 'permission' => 'reports.credit', 'filters' => [...$dates, 'customer_id'], 'description' => 'Debt payments received.'],
            'expenses' => ['title' => 'Expenses', 'group' => 'Expenses', 'permission' => 'reports.expenses', 'filters' => [...$dates, 'expense_category_id', 'user_id'], 'description' => 'All expenses.'],
            'expenses_by_category' => ['title' => 'Expenses by category', 'group' => 'Expenses', 'permission' => 'reports.expenses', 'filters' => $dates, 'description' => 'Expense totals per category.'],
            'gross_profit' => ['title' => 'Gross profit by product', 'group' => 'Profit', 'permission' => 'reports.profit', 'filters' => [...$dates, 'category_id'], 'description' => 'Revenue, cost of goods and margin per product.'],
            'operational_profit' => ['title' => 'Operating profit by day', 'group' => 'Profit', 'permission' => 'reports.profit', 'filters' => $dates, 'description' => 'Sales - cost of goods - expenses.'],
            'transfers' => ['title' => 'Branch transfers', 'group' => 'Transfers', 'permission' => 'reports.transfers', 'filters' => [...$dates, 'transfer_status', 'transfer_type', 'direction'], 'description' => 'Stock sent and received between branches.'],
            'allocations' => ['title' => 'Seller allocations', 'group' => 'Transfers', 'permission' => 'reports.transfers', 'filters' => [...$dates, 'seller_id', 'allocation_status'], 'description' => 'Stock issued to sellers and reconciliations.'],
        ];
    }

    public function build(string $key, array $f, User $user): array
    {
        if (! array_key_exists($key, self::catalogue())) {
            throw new BusinessException(__('Unknown report.'), 'NOT_FOUND', 404);
        }

        [$from, $to] = $this->range($f);
        $showCost = $user->hasAnyPermission(['sales.view_profit', 'reports.profit']);
        $method = 'report'.str_replace('_', '', ucwords($key, '_'));

        $report = $this->{$method}($f, $from, $to, $showCost);
        $report['title'] = self::catalogue()[$key]['title'];
        $report['period'] = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

        return $report;
    }

    // ------------------------------------------------------------------ quotations, proformas, invoices

    private function reportQuotations(array $f, Carbon $from, Carbon $to): array
    {
        return $this->documentsReport(SalesDocument::QUOTATION, $f, $from, $to);
    }

    private function reportProformas(array $f, Carbon $from, Carbon $to): array
    {
        return $this->documentsReport(SalesDocument::PROFORMA, $f, $from, $to);
    }

    private function reportInvoices(array $f, Carbon $from, Carbon $to): array
    {
        return $this->documentsReport(SalesDocument::INVOICE, $f, $from, $to);
    }

    private function documentsReport(string $type, array $f, Carbon $from, Carbon $to): array
    {
        $docs = $this->branch->scope(SalesDocument::query()->where('type', $type), 'branch_id')
            ->with(['sale:id,status,amount_paid,balance', 'convertedTo:id,number'])
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($f['doc_stage'] ?? null, fn ($q, $v) => $q->stage($v))
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->limit(5000)->get();

        $rows = $docs->map(fn (SalesDocument $d) => [
            'number' => $d->number,
            'issue_date' => $d->issue_date?->toDateString(),
            'due_date' => $d->due_date?->toDateString(),
            'customer_name' => $d->customer_name,
            'total' => (float) $d->total,
            'paid' => $d->amount_paid,
            'balance' => $d->balance,
            'stage' => $d->stage,
            'converted_to' => $d->convertedTo?->number,
        ]);
        $count = fn (string ...$stages) => $docs->filter(fn ($d) => in_array($d->stage, $stages, true))->count();

        $columns = [
            'number' => 'Number',
            'issue_date' => ['label' => 'Date', 'type' => 'date'],
            'customer_name' => 'Customer',
            'due_date' => ['label' => $type === SalesDocument::INVOICE ? 'Due date' : 'Valid until', 'type' => 'date'],
            'total' => ['label' => 'Amount', 'type' => 'money'],
        ];
        if ($type !== SalesDocument::QUOTATION) {
            $columns['paid'] = ['label' => 'Paid', 'type' => 'money'];
            $columns['balance'] = ['label' => 'Balance', 'type' => 'money'];
        }
        $columns['stage'] = 'Status';
        if ($type !== SalesDocument::INVOICE) {
            $columns['converted_to'] = 'Became';
        }

        $summary = ['documents' => $docs->count(), 'value' => round((float) $docs->sum('total'), 2)];
        if ($type === SalesDocument::QUOTATION) {
            $answered = $count('accepted', 'declined', 'converted', 'closed', 'expired');
            $won = $count('accepted', 'converted') + $docs->filter(fn ($d) => $d->stage === 'closed' && $d->accepted_at)->count();
            $summary += ['accepted_or_converted' => $won, 'declined' => $count('declined'), 'expired' => $count('expired'), 'win_rate_percent' => $answered ? round($won / $answered * 100, 1) : 0];
        } elseif ($type === SalesDocument::PROFORMA) {
            $summary += ['awaiting_payment' => $count('awaiting_payment'), 'paid' => $count('paid', 'closed'), 'awaiting_value' => round((float) $docs->filter(fn ($d) => $d->stage === 'awaiting_payment')->sum('total'), 2)];
        } else {
            $summary += ['owing' => $count('owing'), 'overdue' => $count('overdue'), 'paid' => $count('paid', 'closed'), 'outstanding' => round((float) $rows->sum('balance'), 2), 'overdue_value' => round((float) $docs->filter(fn ($d) => $d->stage === 'overdue')->sum('balance'), 2)];
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'totals' => array_filter(['total' => (float) $rows->sum('total'), 'paid' => $type !== SalesDocument::QUOTATION ? (float) $rows->sum('paid') : null, 'balance' => $type !== SalesDocument::QUOTATION ? (float) $rows->sum('balance') : null], fn ($v) => $v !== null),
            'summary' => $summary,
        ];
    }

    private function reportInvoiceAging(array $f): array
    {
        $today = today();
        $docs = $this->branch->scope(SalesDocument::query()->where('type', SalesDocument::INVOICE), 'branch_id')
            ->with('sale:id,status,balance,amount_paid')
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->where(fn ($q) => $q->stage('owing')->orWhere(fn ($w) => $w->stage('overdue')))
            ->orderBy('due_date')->get();

        $rows = $docs->map(function (SalesDocument $d) use ($today) {
            $late = $d->due_date && $d->due_date->lt($today) ? (int) $d->due_date->diffInDays($today) : 0;
            $bucket = match (true) {
                $late === 0 => 'current', $late <= 30 => 'd1_30', $late <= 60 => 'd31_60', $late <= 90 => 'd61_90', default => 'd90_plus',
            };

            return ['number' => $d->number, 'customer_name' => $d->customer_name, 'due_date' => $d->due_date?->toDateString(), 'days_late' => $late, 'total' => (float) $d->total]
                + array_merge(array_fill_keys(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'], 0.0), [$bucket => $d->balance, 'balance' => $d->balance]);
        });
        $money = fn ($label) => ['label' => $label, 'type' => 'money'];
        $keys = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'balance'];

        return [
            'columns' => [
                'number' => 'Invoice', 'customer_name' => 'Customer', 'due_date' => ['label' => 'Due date', 'type' => 'date'], 'days_late' => ['label' => 'Days late', 'type' => 'number'],
                'total' => $money('Amount'), 'current' => $money('Not yet due'), 'd1_30' => $money('1-30 days'), 'd31_60' => $money('31-60 days'),
                'd61_90' => $money('61-90 days'), 'd90_plus' => $money('90+ days'), 'balance' => $money('Still owed'),
            ],
            'rows' => $rows,
            'totals' => collect($keys)->mapWithKeys(fn ($k) => [$k => (float) $rows->sum($k)])->all(),
            'summary' => ['invoices' => $rows->count(), 'outstanding' => (float) $rows->sum('balance')],
        ];
    }

    // ------------------------------------------------------------------ sales

    private function reportSales(array $f, Carbon $from, Carbon $to, bool $showCost): array
    {
        $query = $this->branch->scope(Sale::query(), 'sales.branch_id')
            ->with(['branch:id,name', 'cashier', 'customer:id,name,phone'])
            ->whereBetween('sold_at', [$from, $to])
            ->when($f['status'] ?? 'completed', fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->when($f['cashier_id'] ?? null, fn ($q, $v) => $q->where('cashier_id', $v))
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($f['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($f['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->orderByDesc('sold_at')->orderByDesc('sales.id');

        $totals = (clone $query)->toBase()->reorder()->selectRaw('COUNT(*) as count, SUM(total) as total, SUM(returned_amount) as returned_amount, SUM(amount_paid) as amount_paid, SUM(balance) as balance, SUM(cost_total) as cost_total')->first();
        // The customer's name whether it is a saved customer or a name typed at the till.
        $customerLabel = DB::raw('COALESCE((SELECT customers.name FROM customers WHERE customers.id = sales.customer_id), sales.customer_name) as customer_label');

        $columns = [
            'number' => 'Invoice',
            'sold_at' => ['label' => 'Date', 'type' => 'datetime'],
            'branch.name' => 'Branch',
            'customer_label' => 'Customer',
            'cashier.name' => 'Cashier',
            'channel' => 'Channel',
            'total' => ['label' => 'Total', 'type' => 'money'],
            'returned_amount' => ['label' => 'Returned', 'type' => 'money'],
            'amount_paid' => ['label' => 'Paid', 'type' => 'money'],
            'balance' => ['label' => 'Balance', 'type' => 'money'],
            'payment_status' => 'Payment',
            'status' => 'Status',
        ];
        $sumTotals = [
            'total' => (float) $totals->total,
            'returned_amount' => (float) $totals->returned_amount,
            'amount_paid' => (float) $totals->amount_paid,
            'balance' => (float) $totals->balance,
        ];

        if ($showCost) {
            $columns['cost_total'] = ['label' => 'Cost', 'type' => 'money'];
            $columns['profit'] = ['label' => 'Profit', 'type' => 'money'];
            $sumTotals['cost_total'] = (float) $totals->cost_total;
            $sumTotals['profit'] = round((float) $totals->total - (float) $totals->returned_amount - (float) $totals->cost_total, 2);
            $query->select('sales.*')->addSelect([$customerLabel, DB::raw('(sales.total - sales.returned_amount - sales.cost_total) as profit')]);
        } else {
            $query->select(array_map(fn ($c) => "sales.{$c}", ['id', 'number', 'sold_at', 'branch_id', 'customer_id', 'customer_name', 'cashier_id', 'channel', 'total', 'returned_amount', 'amount_paid', 'balance', 'payment_status', 'status']))
                ->addSelect([$customerLabel]);
        }

        return [
            'columns' => $columns,
            'query' => $query,
            'totals' => $sumTotals,
            'summary' => [
                'invoices' => (int) $totals->count,
                'net_sales' => round((float) $totals->total - (float) $totals->returned_amount, 2),
            ] + ($showCost ? ['gross_profit' => round((float) $totals->total - (float) $totals->returned_amount - (float) $totals->cost_total, 2)] : []),
        ];
    }

    private function reportSalesItems(array $f, Carbon $from, Carbon $to, bool $showCost): array
    {
        $query = $this->salesItemsBase($from, $to)
            ->leftJoin('users', 'users.id', '=', 'sales.cashier_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('branches', 'branches.id', '=', 'sales.branch_id')
            ->when($f['product_id'] ?? null, fn ($q, $v) => $q->where('sale_items.product_id', $v))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('products.category_id', $v))
            ->when($f['cashier_id'] ?? null, fn ($q, $v) => $q->where('sales.cashier_id', $v))
            ->select([
                'sales.number', 'sales.sold_at', 'sale_items.product_name', 'sale_items.quantity', 'sale_items.returned_quantity',
                'sale_items.unit_price', 'sale_items.discount', 'sale_items.line_total', 'users.name as cashier',
                DB::raw('COALESCE(customers.name, sales.customer_name) as customer'),
                DB::raw("CASE WHEN sales.is_credit = 1 THEN 'credit' ELSE 'cash' END as sale_type"),
                'sales.payment_status', 'branches.name as branch',
            ])
            ->orderByDesc('sales.sold_at')->orderByDesc('sales.id')->orderBy('sale_items.id');

        if ($showCost) {
            $query->addSelect(['sale_items.unit_cost', DB::raw(self::NET_REVENUE.' - '.self::NET_COST.' as profit')]);
        }

        $totals = (clone $query)->reorder()->select(DB::raw('SUM('.self::NET_QTY.') as qty, SUM('.self::NET_REVENUE.') as revenue, SUM('.self::NET_COST.') as cost'))->first();

        $columns = [
            'number' => 'Invoice',
            'sold_at' => ['label' => 'Date', 'type' => 'datetime'],
            'product_name' => 'Product',
            'quantity' => ['label' => 'Qty', 'type' => 'number'],
            'returned_quantity' => ['label' => 'Returned', 'type' => 'number'],
            'unit_price' => ['label' => 'Price', 'type' => 'money'],
            'discount' => ['label' => 'Discount', 'type' => 'money'],
            'line_total' => ['label' => 'Amount', 'type' => 'money'],
        ];
        if ($showCost) {
            $columns['unit_cost'] = ['label' => 'Unit cost', 'type' => 'money'];
            $columns['profit'] = ['label' => 'Profit', 'type' => 'money'];
        }
        $columns += [
            'cashier' => 'Cashier',
            'customer' => 'Customer',
            'sale_type' => 'Cash / credit',
            'payment_status' => 'Payment',
            'branch' => 'Branch',
        ];

        return [
            'columns' => $columns,
            'query' => $query,
            'totals' => ['line_total' => (float) $totals->revenue] + ($showCost ? ['profit' => (float) $totals->revenue - (float) $totals->cost] : []),
            'summary' => ['quantity_sold' => round((float) $totals->qty, 3), 'net_revenue' => round((float) $totals->revenue, 2)]
                + ($showCost ? ['gross_profit' => round((float) $totals->revenue - (float) $totals->cost, 2)] : []),
        ];
    }

    private function reportPaymentMethods(array $f, Carbon $from, Carbon $to): array
    {
        $rows = $this->branch->scope(Payment::query(), 'branch_id')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("method, COALESCE(provider, '') as provider, type, COUNT(*) as transactions, SUM(amount) as amount")
            ->groupBy('method', 'provider', 'type')
            ->orderBy('method')
            ->get();

        return [
            'columns' => [
                'method' => 'Method',
                'provider' => 'Provider',
                'type' => 'Type',
                'transactions' => ['label' => 'Transactions', 'type' => 'number'],
                'amount' => ['label' => 'Amount', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => ['amount' => (float) $rows->sum('amount')],
        ];
    }

    private function reportReturns(array $f, Carbon $from, Carbon $to): array
    {
        $query = SaleReturnItem::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->join('products', 'products.id', '=', 'sale_return_items.product_id')
            ->leftJoin('users', 'users.id', '=', 'sale_returns.created_by')
            ->whereNull('sale_returns.deleted_at')
            ->whereBetween('sale_returns.created_at', [$from, $to])
            ->when($f['condition'] ?? null, fn ($q, $v) => $q->where('sale_return_items.condition', $v))
            ->select([
                'sale_returns.number', 'sale_returns.created_at', 'sales.number as sale_number', 'products.name as product',
                'sale_return_items.quantity', 'sale_return_items.line_total', 'sale_return_items.condition', 'sale_returns.reason', 'users.name as processed_by',
            ])
            ->orderByDesc('sale_returns.created_at');
        $this->branch->scope($query, 'sale_returns.branch_id');

        $total = (float) (clone $query)->reorder()->sum('sale_return_items.line_total');

        return [
            'columns' => [
                'number' => 'Return no.',
                'created_at' => ['label' => 'Date', 'type' => 'datetime'],
                'sale_number' => 'Invoice',
                'product' => 'Product',
                'quantity' => ['label' => 'Qty', 'type' => 'number'],
                'line_total' => ['label' => 'Value', 'type' => 'money'],
                'condition' => 'Condition',
                'reason' => 'Reason',
                'processed_by' => 'Processed by',
            ],
            'query' => $query,
            'totals' => ['line_total' => $total],
        ];
    }

    // ------------------------------------------------------------ performance

    private function reportProductPerformance(array $f, Carbon $from, Carbon $to, bool $showCost): array
    {
        $sort = $f['sort'] ?? 'top_revenue';
        $limit = min(max((int) ($f['limit'] ?? 50), 1), 500);

        $query = $this->salesItemsBase($from, $to)
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('products.category_id', $v))
            ->groupBy('sale_items.product_id', 'products.name', 'categories.name')
            ->selectRaw('sale_items.product_id, products.name as product, categories.name as category, COUNT(DISTINCT sales.id) as invoices, SUM('.self::NET_QTY.') as quantity, SUM('.self::NET_REVENUE.') as revenue, SUM('.self::NET_REVENUE.' - '.self::NET_COST.') as profit');

        match ($sort) {
            'bottom_revenue' => $query->orderBy('revenue'),
            'top_quantity' => $query->orderByDesc('quantity'),
            'bottom_quantity' => $query->orderBy('quantity'),
            'top_profit' => $query->orderByDesc('profit'),
            default => $query->orderByDesc('revenue'),
        };

        $rows = $query->limit($limit)->get();
        $columns = [
            'product' => 'Product',
            'category' => 'Category',
            'invoices' => ['label' => 'Invoices', 'type' => 'number'],
            'quantity' => ['label' => 'Qty sold', 'type' => 'number'],
            'revenue' => ['label' => 'Revenue', 'type' => 'money'],
        ];
        if ($showCost) {
            $columns['profit'] = ['label' => 'Profit', 'type' => 'money'];
        } else {
            $rows->each(function ($r) {
                unset($r->profit);
            });
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => ['revenue' => (float) $rows->sum('revenue')]];
    }

    private function reportNeverSold(array $f, Carbon $from, Carbon $to): array
    {
        $branchIds = $this->branch->id() ? [$this->branch->id()] : $this->branch->accessibleIds();

        $soldIds = $this->salesItemsBase($from, $to)->distinct()->pluck('sale_items.product_id');

        $query = Product::query()
            ->with(['category:id,name', 'unit:id,short_name'])
            ->withStock($this->branch->id(), $branchIds)
            ->where('is_active', true)
            ->where('is_service', false)
            ->whereNotIn('id', $soldIds)
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->orderBy('name');

        return [
            'columns' => [
                'name' => 'Product',
                'category.name' => 'Category',
                'stock_quantity' => ['label' => 'In stock', 'type' => 'number'],
                'selling_price' => ['label' => 'Price', 'type' => 'money'],
                'created_at' => ['label' => 'Added', 'type' => 'date'],
            ],
            'query' => $query,
            'totals' => [],
        ];
    }

    private function reportStaffPerformance(array $f, Carbon $from, Carbon $to, bool $showCost): array
    {
        $query = $this->branch->scope(Sale::query()->completed(), 'sales.branch_id')
            ->join('users', 'users.id', '=', DB::raw('COALESCE(sales.seller_id, sales.cashier_id)'))
            ->whereBetween('sales.sold_at', [$from, $to])
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.name as staff, COUNT(*) as invoices, SUM(sales.total - sales.returned_amount) as net_sales, AVG(sales.total) as average_sale, SUM(sales.total - sales.returned_amount - sales.cost_total) as profit, SUM(sales.balance) as credit_given')
            ->orderByDesc('net_sales');

        $rows = $query->get();
        $columns = [
            'staff' => 'Staff',
            'invoices' => ['label' => 'Invoices', 'type' => 'number'],
            'net_sales' => ['label' => 'Net sales', 'type' => 'money'],
            'average_sale' => ['label' => 'Average sale', 'type' => 'money'],
            'credit_given' => ['label' => 'Unpaid credit', 'type' => 'money'],
        ];
        if ($showCost) {
            $columns['profit'] = ['label' => 'Profit', 'type' => 'money'];
        } else {
            $rows->each(fn ($r) => $r->offsetUnset('profit'));
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => ['net_sales' => (float) $rows->sum('net_sales')]];
    }

    // ------------------------------------------------------------------ stock

    private function reportStock(array $f, Carbon $from, Carbon $to, bool $showCost): array
    {
        $query = ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->join('branches', 'branches.id', '=', 'product_stocks.branch_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->whereNull('products.deleted_at')
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('products.category_id', $v))
            ->when($f['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('products.name', 'like', "%{$v}%")->orWhere('products.sku', 'like', "%{$v}%")->orWhere('products.barcode', $v)))
            ->when($f['stock_status'] ?? null, function ($q, $v) {
                return match ($v) {
                    'out' => $q->where('product_stocks.quantity', '<=', 0),
                    'low' => $q->where('product_stocks.quantity', '>', 0)->whereColumn('product_stocks.quantity', '<=', 'products.reorder_level'),
                    'ok' => $q->whereColumn('product_stocks.quantity', '>', 'products.reorder_level'),
                    default => $q,
                };
            })
            ->select([
                'products.name as product', 'products.sku', 'categories.name as category', 'branches.name as branch',
                'product_stocks.quantity', 'units.short_name as unit', 'products.reorder_level',
                // Each branch's own prices where it has them.
                DB::raw(BranchPrices::sql('selling_price').' as selling_price'),
                DB::raw('product_stocks.quantity * '.BranchPrices::sql('selling_price').' as retail_value'),
            ])
            ->orderBy('products.name');
        $this->branch->scope($query, 'product_stocks.branch_id');

        if ($showCost || auth()->user()?->hasPermission('products.view_cost')) {
            $query->addSelect([
                DB::raw(BranchPrices::sql('cost_price').' as cost_price'),
                DB::raw('product_stocks.quantity * '.BranchPrices::sql('cost_price').' as cost_value'),
                DB::raw('product_stocks.quantity * ('.BranchPrices::sql('selling_price').' - '.BranchPrices::sql('cost_price').') as expected_profit'),
            ]);
        }

        $totals = (clone $query)->toBase()->reorder()->select(DB::raw('SUM(product_stocks.quantity * '.BranchPrices::sql('cost_price').') as cost_value, SUM(product_stocks.quantity * '.BranchPrices::sql('selling_price').') as retail_value, SUM(product_stocks.quantity) as quantity, COUNT(*) as items'))->first();
        $canCost = $showCost || auth()->user()?->hasPermission('products.view_cost');

        $columns = [
            'product' => 'Product',
            'sku' => 'SKU',
            'category' => 'Category',
            'branch' => 'Branch',
            'quantity' => ['label' => 'Qty', 'type' => 'number'],
            'unit' => 'Unit',
            'reorder_level' => ['label' => 'Reorder at', 'type' => 'number'],
        ];
        if ($canCost) {
            $columns['cost_price'] = ['label' => 'Buying price', 'type' => 'money'];
            $columns['cost_value'] = ['label' => 'Value at buying price', 'type' => 'money'];
        }
        $columns['selling_price'] = ['label' => 'Selling price', 'type' => 'money'];
        $columns['retail_value'] = ['label' => 'Money after selling', 'type' => 'money'];
        if ($canCost) {
            $columns['expected_profit'] = ['label' => 'Expected profit', 'type' => 'money'];
        }

        $retail = (float) $totals->retail_value;
        $cost = (float) $totals->cost_value;

        return [
            'columns' => $columns,
            'query' => $query,
            'totals' => ['quantity' => (float) $totals->quantity, 'retail_value' => $retail] + ($canCost ? ['cost_value' => $cost, 'expected_profit' => $retail - $cost] : []),
            'summary' => ['items' => (int) $totals->items]
                + ($canCost ? ['value_at_buying_price' => round($cost, 2)] : [])
                + ['money_after_selling' => round($retail, 2)]
                + ($canCost ? ['expected_profit' => round($retail - $cost, 2)] : []),
        ];
    }

    private function reportStockMovements(array $f, Carbon $from, Carbon $to): array
    {
        $query = $this->branch->scope(StockMovement::query(), 'stock_movements.branch_id')
            ->with(['product:id,name', 'branch:id,name', 'user'])
            ->whereBetween('created_at', [$from, $to])
            ->when($f['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($f['movement_type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->orderByDesc('id');

        return [
            'columns' => [
                'created_at' => ['label' => 'Date', 'type' => 'datetime'],
                'product.name' => 'Product',
                'branch.name' => 'Branch',
                'type' => 'Type',
                'quantity' => ['label' => 'Qty', 'type' => 'number'],
                'balance_after' => ['label' => 'Balance', 'type' => 'number'],
                'note' => 'Reference',
                'user.name' => 'User',
            ],
            'query' => $query,
            'totals' => [],
        ];
    }

    private function reportExpiry(array $f): array
    {
        $days = (int) ($f['days'] ?? $this->settings->int('expiry_alert_days'));
        $status = $f['expiry_status'] ?? 'all';
        $today = now()->toDateString();

        $query = StockBatch::query()
            ->join('products', 'products.id', '=', 'stock_batches.product_id')
            ->join('branches', 'branches.id', '=', 'stock_batches.branch_id')
            ->whereNull('products.deleted_at')
            ->whereNotNull('stock_batches.expiry_date')
            ->where('stock_batches.quantity_remaining', '>', 0)
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('products.category_id', $v))
            ->when($status === 'expired', fn ($q) => $q->where('stock_batches.expiry_date', '<', $today))
            ->when($status === 'expiring', fn ($q) => $q->whereBetween('stock_batches.expiry_date', [now()->startOfDay(), now()->addDays($days)->endOfDay()]))
            ->when($status === 'all', fn ($q) => $q->where('stock_batches.expiry_date', '<=', now()->addDays($days)->endOfDay()))
            ->select([
                'products.name as product', 'branches.name as branch', 'stock_batches.batch_no', 'stock_batches.expiry_date',
                'stock_batches.quantity_remaining', 'stock_batches.unit_cost',
                DB::raw('stock_batches.quantity_remaining * stock_batches.unit_cost as value'),
            ])
            ->orderBy('stock_batches.expiry_date');
        $this->branch->scope($query, 'stock_batches.branch_id');

        return [
            'columns' => [
                'product' => 'Product',
                'branch' => 'Branch',
                'batch_no' => 'Batch',
                'expiry_date' => ['label' => 'Expiry', 'type' => 'date'],
                'quantity_remaining' => ['label' => 'Qty', 'type' => 'number'],
                'value' => ['label' => 'Value at cost', 'type' => 'money'],
            ],
            'query' => $query,
            'totals' => ['value' => (float) (clone $query)->reorder()->sum(DB::raw('stock_batches.quantity_remaining * stock_batches.unit_cost'))],
        ];
    }

    // -------------------------------------------------------------- purchases

    private function reportPurchases(array $f, Carbon $from, Carbon $to): array
    {
        $query = $this->branch->scope(Purchase::query(), 'purchases.branch_id')
            ->with(['supplier:id,name', 'branch:id,name', 'creator'])
            ->whereBetween('purchase_date', [$from, $to])
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($f['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->orderByDesc('purchase_date');

        $totals = (clone $query)->toBase()->reorder()->selectRaw('SUM(total) as total, SUM(supplier_total) as supplier_total, SUM(amount_paid) as amount_paid, SUM(balance) as balance')->first();

        return [
            'columns' => [
                'number' => 'Purchase',
                'purchase_date' => ['label' => 'Date', 'type' => 'date'],
                'supplier.name' => 'Supplier',
                'supplier_invoice_no' => 'Supplier invoice',
                'branch.name' => 'Branch',
                'supplier_total' => ['label' => 'Goods value', 'type' => 'money'],
                'total' => ['label' => 'Landed total', 'type' => 'money'],
                'amount_paid' => ['label' => 'Paid', 'type' => 'money'],
                'balance' => ['label' => 'Balance', 'type' => 'money'],
                'payment_status' => 'Status',
                'creator.name' => 'Recorded by',
            ],
            'query' => $query,
            'totals' => array_map('floatval', (array) $totals),
        ];
    }

    private function reportSupplierDebts(array $f): array
    {
        $today = today();
        $purchases = $this->branch->scope(Purchase::query(), 'purchases.branch_id')->with(['supplier:id,name,phone', 'branch:id,name'])
            ->where('balance', '>', 0)
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when(($f['due_status'] ?? null) === 'overdue', fn ($q) => $q->where('due_date', '<', $today->toDateString()))
            ->when(($f['due_status'] ?? null) === 'week', fn ($q) => $q->whereBetween('due_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()]))
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->get();

        $rows = $purchases->map(function (Purchase $p) use ($today) {
            $days = $p->due_date ? (int) $today->diffInDays($p->due_date, false) : null;

            return [
                'number' => $p->number, 'supplier' => $p->supplier?->name, 'supplier_phone' => $p->supplier?->phone, 'branch' => $p->branch?->name,
                'purchase_date' => $p->purchase_date?->toDateString(), 'supplier_total' => (float) $p->supplier_total, 'amount_paid' => (float) $p->amount_paid,
                'balance' => (float) $p->balance, 'due_date' => $p->due_date?->toDateString(), 'remind_on' => $p->remind_on?->toDateString(),
                'reminder_channels' => collect((array) $p->reminder_channels)->map(fn ($c) => strtoupper($c) === 'WHATSAPP' ? 'WhatsApp' : ($c === 'sms' ? 'SMS' : __('Email')))->implode(', '),
                'days' => $days === null ? '-' : ($days < 0 ? __(':days days late', ['days' => -$days]) : ($days === 0 ? __('Today') : __('in :days days', ['days' => $days]))),
            ];
        });

        return [
            'columns' => [
                'number' => 'Purchase', 'supplier' => 'Supplier', 'supplier_phone' => 'Phone', 'branch' => 'Branch',
                'purchase_date' => ['label' => 'Bought on', 'type' => 'date'], 'supplier_total' => ['label' => 'Goods value', 'type' => 'money'],
                'amount_paid' => ['label' => 'Paid', 'type' => 'money'], 'balance' => ['label' => 'Still to pay', 'type' => 'money'],
                'due_date' => ['label' => 'Pay by', 'type' => 'date'], 'days' => 'When', 'remind_on' => ['label' => 'Reminder on', 'type' => 'date'], 'reminder_channels' => 'Reminder by',
            ],
            'rows' => $rows,
            'totals' => ['supplier_total' => (float) $rows->sum('supplier_total'), 'amount_paid' => (float) $rows->sum('amount_paid'), 'balance' => (float) $rows->sum('balance')],
            'summary' => [
                'purchases' => $rows->count(),
                'owed' => round((float) $rows->sum('balance'), 2),
                'overdue' => round((float) $purchases->filter(fn ($p) => $p->due_date && $p->due_date->lt($today))->sum('balance'), 2),
                'due_7_days' => round((float) $purchases->filter(fn ($p) => $p->due_date && $p->due_date->between($today, $today->copy()->addDays(7)))->sum('balance'), 2),
            ],
        ];
    }

    private function reportPurchasePlans(array $f, Carbon $from, Carbon $to): array
    {
        $plans = $this->branch->scope(PurchasePlan::query(), 'purchase_plans.branch_id')->with(['supplier:id,name', 'branch:id,name', 'items'])
            ->whereBetween('created_at', [$from, $to])
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($f['plan_status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')->get();

        $rows = $plans->map(fn (PurchasePlan $p) => [
            'number' => $p->number, 'title' => $p->title, 'supplier' => $p->supplier?->name, 'branch' => $p->branch?->name,
            'created_at' => $p->created_at?->toDateString(), 'expected_date' => $p->expected_date?->toDateString(),
            'payment_type' => $p->payment_type === 'credit' ? __('Credit (:days days)', ['days' => $p->credit_days ?? 0]) : __('Cash'),
            'estimated_total' => (float) $p->estimated_total, 'received_total' => (float) $p->received_total,
            'received_percent' => $p->items->sum('quantity') > 0 ? round($p->items->sum('received_quantity') / $p->items->sum('quantity') * 100) : 0,
            'status' => __(str_replace('_', ' ', $p->status)),
        ]);

        return [
            'columns' => [
                'number' => 'Plan', 'title' => 'Title', 'supplier' => 'Supplier', 'branch' => 'Branch', 'created_at' => ['label' => 'Made on', 'type' => 'date'],
                'expected_date' => ['label' => 'Expected', 'type' => 'date'], 'payment_type' => 'Payment', 'estimated_total' => ['label' => 'Planned value', 'type' => 'money'],
                'received_total' => ['label' => 'Received value', 'type' => 'money'], 'received_percent' => ['label' => 'Received %', 'type' => 'number'], 'status' => 'Stage',
            ],
            'rows' => $rows,
            'totals' => ['estimated_total' => (float) $rows->sum('estimated_total'), 'received_total' => (float) $rows->sum('received_total')],
            'summary' => collect(PurchasePlan::STATUSES)->mapWithKeys(fn ($s) => [$s => $plans->where('status', $s)->count()])->all(),
        ];
    }

    private function reportAgencyDays(array $f, Carbon $from, Carbon $to): array
    {
        $days = $this->branch->scope(AgencyDay::query(), 'agency_days.branch_id')->with(['balances.account:id,name', 'branch:id,name', 'opener:id,name', 'closer:id,name'])
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('business_date')->get();
        // One column per line (its closing float, or the opening one while the day is open).
        $accounts = $days->flatMap->balances->pluck('account')->filter()->unique('id')->sortBy('name');
        $rows = $days->map(function (AgencyDay $d) use ($accounts) {
            $row = [
                'business_date' => $d->business_date->toDateString(), 'branch' => $d->branch?->name, 'status' => __($d->status),
                'opening_cash' => (float) $d->opening_cash, 'opening_float' => $d->opening_float, 'opening_total' => $d->opening_total,
                'closing_cash' => $d->status === 'closed' ? (float) $d->closing_cash : null, 'closing_float' => $d->closing_float, 'closing_total' => $d->closing_total,
                'capital_added' => (float) $d->capital_added, 'capital_withdrawn' => (float) $d->capital_withdrawn, 'difference' => $d->difference,
                'opened_by' => $d->opener?->name, 'closed_by' => $d->closer?->name,
            ];
            foreach ($accounts as $a) {
                $b = $d->balances->firstWhere('agency_account_id', $a->id);
                $row['acc_'.$a->id] = $b ? (float) ($b->closing_float ?? $b->opening_float) : null;
            }

            return $row;
        });
        $money = fn ($label) => ['label' => $label, 'type' => 'money'];
        $columns = [
            'business_date' => ['label' => 'Date', 'type' => 'date'], 'branch' => 'Branch', 'status' => 'State',
            'opening_cash' => $money('Cash at opening'), 'opening_float' => $money('Float at opening'), 'opening_total' => $money('Total at opening'),
        ];
        foreach ($accounts as $a) {
            $columns['acc_'.$a->id] = ['label' => $a->name, 'type' => 'money'];
        }
        $columns += [
            'closing_cash' => $money('Cash at closing'), 'closing_float' => $money('Float at closing'), 'closing_total' => $money('Total at closing'),
            'capital_added' => $money('Capital added'), 'capital_withdrawn' => $money('Capital taken out'), 'difference' => $money('Short (-) / over (+)'),
            'opened_by' => 'Opened by', 'closed_by' => 'Closed by',
        ];
        $closed = $days->where('status', 'closed');
        $commissions = (float) $this->branch->scope(AgencyCommission::query(), 'agency_commissions.branch_id')
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])->sum('amount');

        return [
            'columns' => $columns,
            'rows' => $rows,
            'totals' => ['capital_added' => (float) $rows->sum('capital_added'), 'capital_withdrawn' => (float) $rows->sum('capital_withdrawn'), 'difference' => (float) $rows->sum('difference')],
            'summary' => [
                'days' => $days->count(),
                'still_open' => $days->where('status', 'open')->count(),
                'shortages' => round((float) $closed->filter(fn ($d) => $d->difference < 0)->sum('difference'), 2),
                'excesses' => round((float) $closed->filter(fn ($d) => $d->difference > 0)->sum('difference'), 2),
                'commissions' => round($commissions, 2),
                'latest_total' => $days->last() ? ($days->last()->closing_total ?? $days->last()->opening_total) : 0,
            ],
        ];
    }

    private function reportAgencyCommissions(array $f, Carbon $from, Carbon $to): array
    {
        $query = $this->branch->scope(AgencyCommission::query(), 'agency_commissions.branch_id')->with(['account:id,name', 'branch:id,name', 'creator'])
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->when($f['agency_account_id'] ?? null, fn ($q, $v) => $q->where('agency_account_id', $v))
            ->orderByDesc('period')->orderBy('agency_account_id');
        $all = (clone $query)->get();

        return [
            'columns' => [
                'period' => 'Month', 'account.name' => 'Line', 'branch.name' => 'Branch', 'amount' => ['label' => 'Commission', 'type' => 'money'],
                'received_on' => ['label' => 'Received on', 'type' => 'date'], 'reference' => 'Reference', 'creator.name' => 'Recorded by',
            ],
            'query' => $query,
            'totals' => ['amount' => (float) $all->sum('amount')],
            // One figure per line, then the overall total (the page shows them as tiles).
            'summary' => $all->groupBy(fn ($c) => $c->account?->name ?? '-')->map(fn ($r) => round((float) $r->sum('amount'), 2))->sortDesc()->all()
                + ['total' => round((float) $all->sum('amount'), 2), 'months' => $all->pluck('period')->unique()->count()],
        ];
    }

    private function reportOnlinePayments(array $f, Carbon $from, Carbon $to): array
    {
        $query = $this->branch->scope(OnlinePayment::query(), 'online_payments.branch_id')
            ->with(['branch:id,name', 'sale:id,number', 'order:id,number', 'requester'])
            ->whereBetween('created_at', [$from, $to])
            ->when(($f['online_status'] ?? null) === 'unused', fn ($q) => $q->where('status', 'completed')->whereNull('sale_id')->whereNull('used_at'))
            ->when(in_array($f['online_status'] ?? null, OnlinePayment::STATUSES, true), fn ($q) => $q->where('status', $f['online_status']))
            ->when($f['online_purpose'] ?? null, fn ($q, $v) => $q->where('purpose', $v))
            ->latest('id');
        $all = (clone $query)->get();
        $done = $all->where('status', 'completed');

        return [
            'columns' => [
                'number' => 'Number', 'created_at' => ['label' => 'Requested', 'type' => 'datetime'], 'purpose_label' => 'For', 'branch.name' => 'Branch',
                'payer_name' => 'Customer', 'phone' => 'Phone', 'provider_name' => 'Network', 'status_label' => 'Status',
                'amount' => ['label' => 'Amount', 'type' => 'money'], 'fee' => ['label' => 'Fee', 'type' => 'money'],
                'external_reference' => 'Transaction ID', 'sale.number' => 'Sale', 'order.number' => 'Order', 'requester.name' => 'Requested by',
            ],
            'query' => $query,
            'totals' => ['amount' => (float) $done->sum('amount'), 'fee' => (float) $done->sum('fee')],
            'summary' => [
                'received' => round((float) $done->sum('amount'), 2),
                'fees' => round((float) $done->sum('fee'), 2),
                'net' => round((float) $done->sum('amount') - (float) $done->sum('fee'), 2),
                'paid' => $done->count(),
                'not_paid' => $all->whereIn('status', ['failed', 'expired', 'voided', 'cancelled'])->count(),
                'unused' => round((float) $done->filter(fn ($p) => ! $p->sale_id && ! $p->used_at)->sum('amount'), 2),
            ],
        ];
    }

    private function reportSupplierBalances(array $f): array
    {
        $query = Supplier::query()
            ->search($f['search'] ?? null)
            ->where('balance', '!=', 0)
            ->withCount(['purchases as unpaid_purchases' => fn ($q) => $q->where('balance', '>', 0)])
            ->withMin(['purchases as next_due' => fn ($q) => $q->where('balance', '>', 0)], 'due_date')
            ->orderByDesc('balance');

        return [
            'columns' => [
                'code' => 'Code',
                'name' => 'Supplier',
                'phone' => 'Phone',
                'unpaid_purchases' => ['label' => 'Unpaid invoices', 'type' => 'number'],
                'next_due' => ['label' => 'Next due', 'type' => 'date'],
                'balance' => ['label' => 'Balance owed', 'type' => 'money'],
            ],
            'query' => $query,
            'totals' => ['balance' => (float) (clone $query)->reorder()->sum('balance')],
        ];
    }

    // ----------------------------------------------------------------- credit

    private function reportDebtors(array $f): array
    {
        $today = now()->startOfDay();
        $customers = Customer::query()
            ->search($f['search'] ?? null)
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->get(['id', 'code', 'name', 'phone', 'balance', 'credit_limit']);

        $sales = Sale::query()->completed()
            ->whereIn('customer_id', $customers->pluck('id'))
            ->where('balance', '>', 0)
            ->get(['customer_id', 'balance', 'due_date', 'sold_at'])
            ->groupBy('customer_id');

        $rows = $customers->map(function (Customer $c) use ($sales, $today) {
            $buckets = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0];
            foreach ($sales->get($c->id, collect()) as $sale) {
                $due = $sale->due_date ?? $sale->sold_at;
                $late = $due->lt($today) ? (int) $due->diffInDays($today, true) : 0;
                $bucket = match (true) {
                    $late === 0 => 'current',
                    $late <= 30 => 'd1_30',
                    $late <= 60 => 'd31_60',
                    $late <= 90 => 'd61_90',
                    default => 'd90_plus',
                };
                $buckets[$bucket] += (float) $sale->balance;
            }

            return ['code' => $c->code, 'name' => $c->name, 'phone' => $c->phone, 'credit_limit' => (float) $c->credit_limit, 'balance' => (float) $c->balance] + $buckets;
        });

        $money = fn ($label) => ['label' => $label, 'type' => 'money'];

        return [
            'columns' => [
                'code' => 'Code', 'name' => 'Customer', 'phone' => 'Phone',
                'current' => $money('Not yet due'), 'd1_30' => $money('1-30 days'), 'd31_60' => $money('31-60 days'),
                'd61_90' => $money('61-90 days'), 'd90_plus' => $money('90+ days'), 'balance' => $money('Total owed'),
            ],
            'rows' => $rows,
            'totals' => collect(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'balance'])->mapWithKeys(fn ($k) => [$k => (float) $rows->sum($k)])->all(),
        ];
    }

    private function reportCreditSales(array $f, Carbon $from, Carbon $to): array
    {
        $status = $f['credit_status'] ?? 'unpaid';
        $query = $this->branch->scope(Sale::query()->completed(), 'sales.branch_id')
            ->with(['customer:id,name,phone', 'cashier', 'branch:id,name'])
            ->whereNotNull('customer_id')
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v));

        match ($status) {
            'overdue' => $query->where('balance', '>', 0)->where('due_date', '<', now()->toDateString()),
            'due' => $query->where('balance', '>', 0)->whereBetween('due_date', [$from, $to]),
            'paid' => $query->where('payment_status', 'paid')->where('channel', '!=', 'allocation')->whereNotNull('due_date')->whereBetween('fully_paid_at', [$from, $to]),
            default => $query->where('balance', '>', 0)->whereBetween('sold_at', [$from, $to]),
        };
        $query->orderBy('due_date');

        $totals = (clone $query)->toBase()->reorder()->selectRaw('SUM(total) as total, SUM(amount_paid) as amount_paid, SUM(balance) as balance')->first();

        return [
            'columns' => [
                'number' => 'Invoice',
                'sold_at' => ['label' => 'Sold', 'type' => 'date'],
                'customer.name' => 'Customer',
                'customer.phone' => 'Phone',
                'branch.name' => 'Branch',
                'total' => ['label' => 'Total', 'type' => 'money'],
                'amount_paid' => ['label' => 'Paid', 'type' => 'money'],
                'balance' => ['label' => 'Balance', 'type' => 'money'],
                'due_date' => ['label' => 'Due', 'type' => 'date'],
                'fully_paid_at' => ['label' => 'Cleared', 'type' => 'date'],
                'cashier.name' => 'Sold by',
            ],
            'query' => $query,
            'totals' => array_map('floatval', (array) $totals),
        ];
    }

    private function reportDebtPayments(array $f, Carbon $from, Carbon $to): array
    {
        $query = $this->branch->scope(Payment::query(), 'payments.branch_id')
            ->with(['customer:id,name,phone', 'sale:id,number', 'receiver'])
            ->where('type', 'debt')
            ->whereBetween('paid_at', [$from, $to])
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->orderByDesc('paid_at');

        return [
            'columns' => [
                'number' => 'Receipt',
                'paid_at' => ['label' => 'Date', 'type' => 'datetime'],
                'customer.name' => 'Customer',
                'sale.number' => 'Invoice',
                'method' => 'Method',
                'provider' => 'Provider',
                'reference' => 'Reference',
                'amount' => ['label' => 'Amount', 'type' => 'money'],
                'receiver.name' => 'Received by',
            ],
            'query' => $query,
            'totals' => ['amount' => (float) (clone $query)->reorder()->sum('amount')],
        ];
    }

    // --------------------------------------------------------------- expenses

    private function reportExpenses(array $f, Carbon $from, Carbon $to): array
    {
        $query = $this->branch->scope(Expense::query(), 'expenses.branch_id')
            ->with(['category:id,name', 'branch:id,name', 'creator'])
            ->whereBetween('expense_date', [$from, $to])
            ->when($f['expense_category_id'] ?? null, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('created_by', $v))
            ->orderByDesc('expense_date');

        return [
            'columns' => [
                'number' => 'No.',
                'expense_date' => ['label' => 'Date', 'type' => 'date'],
                'title' => 'Expense',
                'category.name' => 'Category',
                'description' => 'Description',
                'branch.name' => 'Branch',
                'payment_method' => 'Paid by',
                'amount' => ['label' => 'Amount', 'type' => 'money'],
                'creator.name' => 'Recorded by',
            ],
            'query' => $query,
            'totals' => ['amount' => (float) (clone $query)->reorder()->sum('amount')],
        ];
    }

    private function reportExpensesByCategory(array $f, Carbon $from, Carbon $to): array
    {
        $rows = $this->branch->scope(Expense::query(), 'expenses.branch_id')
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->whereBetween('expense_date', [$from, $to])
            ->groupBy('expense_categories.name')
            ->selectRaw("COALESCE(expense_categories.name, 'Uncategorised') as category, COUNT(*) as entries, SUM(expenses.amount) as amount")
            ->orderByDesc('amount')
            ->get();

        return [
            'columns' => [
                'category' => 'Category',
                'entries' => ['label' => 'Entries', 'type' => 'number'],
                'amount' => ['label' => 'Amount', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => ['amount' => (float) $rows->sum('amount')],
        ];
    }

    // ----------------------------------------------------------------- profit

    private function reportGrossProfit(array $f, Carbon $from, Carbon $to): array
    {
        $rows = $this->salesItemsBase($from, $to)
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('products.category_id', $v))
            ->groupBy('sale_items.product_id', 'products.name', 'categories.name')
            ->selectRaw('products.name as product, categories.name as category, SUM('.self::NET_QTY.') as quantity, SUM('.self::NET_REVENUE.') as revenue, SUM('.self::NET_COST.') as cost')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($r) {
                $r->profit = round((float) $r->revenue - (float) $r->cost, 2);
                $r->margin = (float) $r->revenue > 0 ? round($r->profit / (float) $r->revenue * 100, 1) : 0;

                return $r;
            });

        return [
            'columns' => [
                'product' => 'Product',
                'category' => 'Category',
                'quantity' => ['label' => 'Qty sold', 'type' => 'number'],
                'revenue' => ['label' => 'Revenue', 'type' => 'money'],
                'cost' => ['label' => 'Cost of goods', 'type' => 'money'],
                'profit' => ['label' => 'Gross profit', 'type' => 'money'],
                'margin' => ['label' => 'Margin %', 'type' => 'number'],
            ],
            'rows' => $rows,
            'totals' => ['revenue' => (float) $rows->sum('revenue'), 'cost' => (float) $rows->sum('cost'), 'profit' => (float) $rows->sum('profit')],
            'summary' => ['note' => 'Line revenue after returns; invoice-level discounts and delivery fees are in the operating profit report.'],
        ];
    }

    private function reportOperationalProfit(array $f, Carbon $from, Carbon $to): array
    {
        if ($from->diffInDays($to, true) > 400) {
            throw new BusinessException(__('Choose a period of at most 400 days for this report.'));
        }

        $sales = $this->branch->scope(Sale::query()->completed(), 'branch_id')
            ->whereBetween('sold_at', [$from, $to])
            ->selectRaw('DATE(sold_at) as day, SUM(total - returned_amount) as sales')
            ->groupBy(DB::raw('DATE(sold_at)'))
            ->pluck('sales', 'day');

        $cost = $this->salesItemsBase($from, $to)
            ->selectRaw('DATE(sales.sold_at) as day, SUM('.self::NET_COST.') as cost')
            ->groupBy(DB::raw('DATE(sales.sold_at)'))
            ->pluck('cost', 'day');

        $expenses = $this->branch->scope(Expense::query(), 'branch_id')
            ->whereBetween('expense_date', [$from, $to])
            ->selectRaw('expense_date as day, SUM(amount) as amount')
            ->groupBy('expense_date')
            ->pluck('amount', 'day')
            ->mapWithKeys(fn ($v, $k) => [substr((string) $k, 0, 10) => $v]);

        $rows = collect();
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $s = (float) ($sales[$key] ?? 0);
            $c = (float) ($cost[$key] ?? 0);
            $e = (float) ($expenses[$key] ?? 0);
            if ($s == 0.0 && $c == 0.0 && $e == 0.0) {
                continue;
            }
            $rows->push(['day' => $key, 'sales' => $s, 'cost' => $c, 'gross_profit' => round($s - $c, 2), 'expenses' => $e, 'net_profit' => round($s - $c - $e, 2)]);
        }

        return [
            'columns' => [
                'day' => ['label' => 'Date', 'type' => 'date'],
                'sales' => ['label' => 'Net sales', 'type' => 'money'],
                'cost' => ['label' => 'Cost of goods', 'type' => 'money'],
                'gross_profit' => ['label' => 'Gross profit', 'type' => 'money'],
                'expenses' => ['label' => 'Expenses', 'type' => 'money'],
                'net_profit' => ['label' => 'Operating profit', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => collect(['sales', 'cost', 'gross_profit', 'expenses', 'net_profit'])->mapWithKeys(fn ($k) => [$k => (float) $rows->sum($k)])->all(),
        ];
    }

    // -------------------------------------------------------------- transfers

    private function reportTransfers(array $f, Carbon $from, Carbon $to): array
    {
        $branchId = $this->branch->id();
        $direction = $f['direction'] ?? 'all';
        $ids = $branchId ? [$branchId] : $this->branch->accessibleIds();

        $query = Transfer::query()
            ->with(['fromBranch:id,name', 'toBranch:id,name', 'sender', 'receiver'])
            ->whereBetween('sent_at', [$from, $to])
            ->when($f['transfer_status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['transfer_type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(! $this->branch->viewingAll(), function ($q) use ($direction, $ids) {
                return match ($direction) {
                    'out' => $q->whereIn('from_branch_id', $ids),
                    'in' => $q->whereIn('to_branch_id', $ids),
                    default => $q->where(fn ($w) => $w->whereIn('from_branch_id', $ids)->orWhereIn('to_branch_id', $ids)),
                };
            })
            ->orderByDesc('sent_at');

        return [
            'columns' => [
                'number' => 'Transfer',
                'sent_at' => ['label' => 'Sent', 'type' => 'datetime'],
                'from_branch.name' => 'From',
                'to_branch.name' => 'To',
                'type' => 'Type',
                'status' => 'Status',
                'total_cost' => ['label' => 'Cost value', 'type' => 'money'],
                'total_amount' => ['label' => 'Charged', 'type' => 'money'],
                'payment_status' => 'Payment',
                'sender.name' => 'Sent by',
                'receiver.name' => 'Received by',
            ],
            'query' => $query,
            'totals' => [
                'total_cost' => (float) (clone $query)->reorder()->sum('total_cost'),
                'total_amount' => (float) (clone $query)->reorder()->sum('total_amount'),
            ],
        ];
    }

    private function reportAllocations(array $f, Carbon $from, Carbon $to): array
    {
        $query = $this->branch->scope(Allocation::query(), 'allocations.branch_id')
            ->with(['seller', 'branch:id,name'])
            ->whereBetween('issued_at', [$from, $to])
            ->when($f['seller_id'] ?? null, fn ($q, $v) => $q->where('seller_id', $v))
            ->when($f['allocation_status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('issued_at');

        $totals = (clone $query)->toBase()->reorder()->selectRaw('SUM(expected_amount) as expected_amount, SUM(sold_amount) as sold_amount, SUM(amount_received) as amount_received, SUM(shortage) as shortage')->first();

        return [
            'columns' => [
                'number' => 'Allocation',
                'issued_at' => ['label' => 'Issued', 'type' => 'datetime'],
                'seller.name' => 'Seller',
                'branch.name' => 'Branch',
                'status' => 'Status',
                'expected_amount' => ['label' => 'Issued value', 'type' => 'money'],
                'sold_amount' => ['label' => 'Sold', 'type' => 'money'],
                'amount_received' => ['label' => 'Received', 'type' => 'money'],
                'shortage' => ['label' => 'Shortage', 'type' => 'money'],
                'reconciled_at' => ['label' => 'Reconciled', 'type' => 'datetime'],
            ],
            'query' => $query,
            'totals' => array_map('floatval', (array) $totals),
        ];
    }

    // ---------------------------------------------------------------- helpers

    /** Completed sales items in the period, branch-scoped, joined to sales and products. */
    private function salesItemsBase(Carbon $from, Carbon $to): Builder
    {
        $query = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.sold_at', [$from, $to]);

        return $this->branch->scope($query, 'sales.branch_id');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(array $f): array
    {
        $from = ! empty($f['date_from']) ? Carbon::parse($f['date_from'])->startOfDay() : now()->startOfMonth();
        $to = ! empty($f['date_to']) ? Carbon::parse($f['date_to'])->endOfDay() : now()->endOfDay();
        if ($from->gt($to)) {
            throw new BusinessException(__('The start date must be before the end date.'));
        }

        return [$from, $to];
    }
}
