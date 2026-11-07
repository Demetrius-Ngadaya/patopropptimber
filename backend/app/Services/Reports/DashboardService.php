<?php

namespace App\Services\Reports;

use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Finance\Expense;
use App\Models\Inventory\ProductStock;
use App\Models\Inventory\StockBatch;
use App\Models\Inventory\Transfer;
use App\Models\Sales\Order;
use App\Models\Sales\Payment;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use App\Support\BranchContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private readonly BranchContext $branch,
        private readonly SettingsService $settings,
    ) {}

    public function overview(User $user, int $year): array
    {
        $financials = $user->hasPermission('dashboard.view_financials');
        $profit = $financials && $user->hasAnyPermission(['sales.view_profit', 'reports.profit']);

        $data = [
            'alerts' => $this->alerts(),
            'kpis' => $financials ? $this->kpis($profit) : null,
            'monthly' => $financials ? $this->monthly($year, $profit) : null,
            'top_products' => $financials ? $this->topProducts() : null,
            'payment_methods' => $financials ? $this->paymentMethods() : null,
            'recent_sales' => $this->recentSales($user),
            'branches' => $financials && $this->branch->viewingAll() && $user->hasPermission('dashboard.view_all_branches') ? $this->branchComparison() : null,
            'show_profit' => $profit,
            'year' => $year,
        ];

        return $data;
    }

    /** Sales, quantity, profit and expense totals for standard periods. */
    public function periods(bool $profit): array
    {
        $now = now();
        $q = fn (int $n) => [Carbon::create($now->year, ($n - 1) * 3 + 1, 1)->startOfDay(), Carbon::create($now->year, $n * 3, 1)->endOfMonth()];

        $periods = [
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'q1' => $q(1), 'q2' => $q(2), 'q3' => $q(3), 'q4' => $q(4),
            'h1' => [Carbon::create($now->year, 1, 1), Carbon::create($now->year, 6, 30)->endOfDay()],
            'h2' => [Carbon::create($now->year, 7, 1), Carbon::create($now->year, 12, 31)->endOfDay()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'all_time' => [Carbon::create(2000, 1, 1), $now->copy()->endOfDay()],
        ];

        $result = [];
        foreach ($periods as $key => [$from, $to]) {
            $result[$key] = $this->summary($from, $to, $profit) + ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        }

        return $result;
    }

    public function summary(Carbon $from, Carbon $to, bool $profit): array
    {
        $sales = $this->branch->scope(Sale::query()->completed(), 'branch_id')
            ->whereBetween('sold_at', [$from, $to])
            ->toBase()
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(total - returned_amount), 0) as net_sales, COALESCE(SUM(cost_total), 0) as cost, COALESCE(SUM(balance), 0) as credit')
            ->first();

        $quantity = $this->branch->scope(DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id'), 'sales.branch_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.sold_at', [$from, $to])
            ->sum(DB::raw('sale_items.quantity - sale_items.returned_quantity'));

        $expenses = (float) $this->branch->scope(Expense::query(), 'branch_id')->whereBetween('expense_date', [$from, $to])->sum('amount');

        $row = [
            'invoices' => (int) $sales->invoices,
            'net_sales' => round((float) $sales->net_sales, 2),
            'quantity' => round((float) $quantity, 3),
            'credit_given' => round((float) $sales->credit, 2),
            'expenses' => round($expenses, 2),
        ];
        if ($profit) {
            // Cost of returned goods is excluded proportionally via the sale_items calculation.
            $cogs = (float) $this->branch->scope(DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id'), 'sales.branch_id')
                ->where('sales.status', Sale::STATUS_COMPLETED)->whereNull('sales.deleted_at')
                ->whereBetween('sales.sold_at', [$from, $to])
                ->sum(DB::raw('sale_items.unit_cost * (sale_items.quantity - sale_items.returned_quantity)'));
            $row['gross_profit'] = round($row['net_sales'] - $cogs, 2);
            $row['net_profit'] = round($row['gross_profit'] - $expenses, 2);
        }

        return $row;
    }

    private function kpis(bool $profit): array
    {
        $today = $this->summary(now()->startOfDay(), now()->endOfDay(), $profit);
        $month = $this->summary(now()->startOfMonth(), now()->endOfDay(), $profit);
        $lastMonth = $this->summary(now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth(), $profit);

        $collectedToday = (float) $this->branch->scope(Payment::query(), 'branch_id')
            ->whereBetween('paid_at', [now()->startOfDay(), now()->endOfDay()])->sum('amount');

        return [
            'today' => $today + ['collected' => round($collectedToday, 2)],
            'month' => $month,
            'last_month' => $lastMonth,
            'receivables' => round((float) Customer::query()->where('balance', '>', 0)->sum('balance'), 2),
            'debtors' => Customer::query()->where('balance', '>', 0)->count(),
        ];
    }

    private function monthly(int $year, bool $profit): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $from = Carbon::create($year, $m, 1)->startOfDay();
            $to = $from->copy()->endOfMonth();
            $months[] = ['month' => $from->format('M')] + $this->summary($from, $to, $profit);
        }

        return $months;
    }

    private function topProducts(): array
    {
        return $this->branch->scope(DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id'), 'sales.branch_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)->whereNull('sales.deleted_at')
            ->whereBetween('sales.sold_at', [now()->startOfMonth(), now()->endOfDay()])
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->selectRaw('sale_items.product_name as name, SUM(sale_items.quantity - sale_items.returned_quantity) as quantity, SUM(sale_items.line_total * (sale_items.quantity - sale_items.returned_quantity) / NULLIF(sale_items.quantity, 0)) as revenue')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => (float) $r->quantity, 'revenue' => round((float) $r->revenue, 2)])
            ->all();
    }

    private function paymentMethods(): array
    {
        return $this->branch->scope(Payment::query(), 'branch_id')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfDay()])
            ->groupBy('method')
            ->selectRaw('method, SUM(amount) as amount')
            ->get()
            ->map(fn ($r) => ['method' => $r->method, 'amount' => round((float) $r->amount, 2)])
            ->all();
    }

    private function recentSales(User $user): array
    {
        if (! $user->hasPermission('sales.view')) {
            return [];
        }

        return $this->branch->scope(Sale::query(), 'branch_id')
            ->when(! $user->hasPermission('sales.view_all_users'), fn ($q) => $q->where('cashier_id', $user->id))
            ->with('cashier')
            ->latest('sold_at')
            ->limit(8)
            ->get(['id', 'number', 'sold_at', 'customer_name', 'total', 'payment_status', 'status', 'cashier_id'])
            ->all();
    }

    private function branchComparison(): array
    {
        $from = now()->startOfMonth();
        $to = now()->endOfDay();

        return DB::table('branches')
            ->whereNull('branches.deleted_at')
            ->leftJoin('sales', function ($join) use ($from, $to) {
                $join->on('sales.branch_id', '=', 'branches.id')
                    ->where('sales.status', '=', Sale::STATUS_COMPLETED)
                    ->whereNull('sales.deleted_at')
                    ->whereBetween('sales.sold_at', [$from, $to]);
            })
            ->groupBy('branches.id', 'branches.name')
            ->selectRaw('branches.name, COUNT(sales.id) as invoices, COALESCE(SUM(sales.total - sales.returned_amount), 0) as net_sales')
            ->orderByDesc('net_sales')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'invoices' => (int) $r->invoices, 'net_sales' => round((float) $r->net_sales, 2)])
            ->all();
    }

    public function alerts(): array
    {
        $branchScoped = fn ($q, $col = 'branch_id') => $this->branch->scope($q, $col);
        $alertDays = $this->settings->int('expiry_alert_days');

        $lowStock = $branchScoped(ProductStock::query()->join('products', 'products.id', '=', 'product_stocks.product_id'), 'product_stocks.branch_id')
            ->whereNull('products.deleted_at')->where('products.is_active', true)->where('products.is_service', false)
            ->where('product_stocks.quantity', '>', 0)
            ->whereColumn('product_stocks.quantity', '<=', 'products.reorder_level')
            ->count();

        $outOfStock = $branchScoped(ProductStock::query()->join('products', 'products.id', '=', 'product_stocks.product_id'), 'product_stocks.branch_id')
            ->whereNull('products.deleted_at')->where('products.is_active', true)->where('products.is_service', false)
            ->where('product_stocks.quantity', '<=', 0)
            ->count();

        $expired = $branchScoped(StockBatch::query())->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiry_date')->where('expiry_date', '<', now()->startOfDay())->count();

        $expiring = $branchScoped(StockBatch::query())->where('quantity_remaining', '>', 0)
            ->whereBetween('expiry_date', [now()->startOfDay(), now()->addDays($alertDays)->endOfDay()])->count();

        $pendingOrders = $branchScoped(Order::query())->whereIn('status', ['pending', 'confirmed', 'processing', 'ready', 'out_for_delivery'])->count();

        $incomingTransfers = Transfer::query()->where('status', 'in_transit')
            ->when($this->branch->id(), fn ($q, $id) => $q->where('to_branch_id', $id), fn ($q) => $q->whereIn('to_branch_id', $this->branch->accessibleIds() ?: [0]))
            ->count();

        $overdueDebts = $branchScoped(Sale::query()->completed())->where('balance', '>', 0)
            ->whereNotNull('customer_id')->where('due_date', '<', now()->startOfDay())->count();

        return [
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'expired_batches' => $expired,
            'expiring_batches' => $expiring,
            'pending_orders' => $pendingOrders,
            'incoming_transfers' => $incomingTransfers,
            'overdue_debts' => $overdueDebts,
        ];
    }
}
