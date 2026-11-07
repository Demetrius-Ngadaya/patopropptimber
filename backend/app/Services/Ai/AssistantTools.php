<?php

namespace App\Services\Ai;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Crm\SavingsAccount;
use App\Models\Hr\Attendance;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\PayrollRun;
use App\Services\Crm\CustomerService;
use App\Services\Finance\FinancialReportService;
use App\Services\Reports\DashboardService;
use App\Services\Reports\ReportService;
use App\Support\BranchContext;
use Illuminate\Support\Carbon;

/**
 * The read-only tools the assistant may call. Each tool declares the
 * permission it needs: tools the user cannot use are never offered to the
 * model, and every call is checked again before it runs. All data access
 * goes through the same services (and branch scoping) as the normal screens.
 */
class AssistantTools
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly FinancialReportService $financials,
        private readonly DashboardService $dashboard,
        private readonly DemandForecaster $forecaster,
        private readonly AnomalyDetector $anomalies,
        private readonly CreditScorer $scorer,
        private readonly CustomerService $customers,
        private readonly BranchContext $branch,
    ) {}

    /** Tool definitions (Anthropic format) available to this user. */
    public function definitionsFor(User $user): array
    {
        return collect($this->catalogue($user))
            ->filter(fn ($t) => $user->hasAnyPermission((array) $t['permission']))
            ->map(fn ($t, $name) => ['name' => $name, 'description' => $t['description'], 'input_schema' => $t['schema']])
            ->values()->all();
    }

    public function run(User $user, string $name, array $input): array
    {
        $tool = $this->catalogue($user)[$name] ?? null;
        if (! $tool) {
            return ['error' => "Unknown tool {$name}."];
        }
        if (! $user->hasAnyPermission((array) $tool['permission'])) {
            return ['error' => 'The user does not have permission for this information.'];
        }

        try {
            return ($tool['run'])($input);
        } catch (BusinessException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function catalogue(User $user): array
    {
        $dates = [
            'date_from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
            'date_to' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
        ];
        $reportKeys = collect(ReportService::catalogue())->filter(fn ($r) => $user->hasPermission($r['permission']))->keys()->all();

        return [
            'business_overview' => [
                'permission' => 'dashboard.view',
                'description' => 'Headline figures for a period: sales, number of sales, payments by method, profit (if allowed), expenses, debts collected, top products. Use for "how did we do today/this week/this month".',
                'schema' => ['type' => 'object', 'properties' => $dates, 'required' => ['date_from', 'date_to']],
                'run' => fn ($i) => $this->dashboard->summary(Carbon::parse($i['date_from'])->startOfDay(), Carbon::parse($i['date_to'])->endOfDay(), $user->hasAnyPermission(['dashboard.view_financials', 'reports.profit'])),
            ],
            'run_report' => [
                'permission' => array_values(array_unique(array_column(ReportService::catalogue(), 'permission'))),
                'description' => 'Run one of the business reports and get its totals plus the first rows. Reports: '.collect(ReportService::catalogue())->only($reportKeys)->map(fn ($r, $k) => "{$k} ({$r['description']})")->implode('; '),
                'schema' => ['type' => 'object', 'properties' => $dates + [
                    'report' => ['type' => 'string', 'enum' => $reportKeys ?: ['none']],
                    'filters' => ['type' => 'object', 'description' => 'Optional extra filters, e.g. {"sort":"revenue_desc","limit":10,"stock_status":"low","search":"sugar"}'],
                    'rows' => ['type' => 'integer', 'description' => 'How many rows to return (max 25)'],
                ], 'required' => ['report']],
                'run' => function ($i) use ($user, $reportKeys) {
                    if (! in_array($i['report'] ?? '', $reportKeys, true)) {
                        return ['error' => 'Report not available to this user.'];
                    }
                    $report = $this->reports->build($i['report'], array_merge((array) ($i['filters'] ?? []), array_intersect_key($i, array_flip(['date_from', 'date_to']))), $user);
                    $rows = isset($report['query']) ? $report['query']->limit(min(25, (int) ($i['rows'] ?? 15)))->get()->map(fn ($r) => is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r)->all() : ($report['rows'] ?? []);
                    unset($report['query']);

                    return $report + ['rows' => $rows];
                },
            ],
            'financial_statement' => [
                'permission' => 'accounting.statements',
                'description' => 'Accounting statements from the general ledger: profit_and_loss, balance_sheet (as of date_to), cash_flow, trial_balance, vat, aged_receivables, aged_payables.',
                'schema' => ['type' => 'object', 'properties' => $dates + [
                    'statement' => ['type' => 'string', 'enum' => ['profit_and_loss', 'balance_sheet', 'cash_flow', 'trial_balance', 'vat', 'aged_receivables', 'aged_payables']],
                ], 'required' => ['statement']],
                'run' => function ($i) {
                    $from = $i['date_from'] ?? now()->startOfMonth()->toDateString();
                    $to = $i['date_to'] ?? now()->toDateString();
                    $branch = $this->branch->id();

                    return match ($i['statement']) {
                        'profit_and_loss' => $this->financials->profitAndLoss($from, $to, $branch, true),
                        'balance_sheet' => $this->financials->balanceSheet($to, $branch),
                        'cash_flow' => $this->financials->cashFlow($from, $to, $branch),
                        'trial_balance' => $this->financials->trialBalance($from, $to, $branch),
                        'vat' => $this->financials->vat($from, $to, $branch),
                        'aged_receivables' => $this->financials->agedReceivables($to, $branch),
                        'aged_payables' => $this->financials->agedPayables($to, $branch),
                        default => ['error' => 'Unknown statement.'],
                    };
                },
            ],
            'reorder_suggestions' => [
                'permission' => 'ai.forecast',
                'description' => 'AI demand forecast per product with days of stock left, stock-out date and how much to order. Filter by status: out_of_stock, reorder_now, reorder_soon, dead_stock, overstock.',
                'schema' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['out_of_stock', 'reorder_now', 'reorder_soon', 'dead_stock', 'overstock', 'ok']],
                    'search' => ['type' => 'string'],
                ]],
                'run' => fn ($i) => ['products' => collect($this->forecaster->reorderPlan($this->branch->id(), $i['search'] ?? null, 500))
                    ->when($i['status'] ?? null, fn ($c, $s) => $c->where('status', $s))->take(25)->values()->all()],
            ],
            'product_forecast' => [
                'permission' => 'ai.forecast',
                'description' => 'Daily sales forecast for the next 30 days for one product (search by name, SKU or barcode).',
                'schema' => ['type' => 'object', 'properties' => ['product' => ['type' => 'string']], 'required' => ['product']],
                'run' => function ($i) {
                    $product = Product::query()->search($i['product'])->first();
                    if (! $product) {
                        return ['error' => 'No product matches that name.'];
                    }
                    $f = $this->forecaster->productForecast($product, $this->branch->id());

                    return ['product' => $product->name, 'summary' => $f['summary'], 'next_30_days_total' => round(array_sum(array_column($f['forecast'], 'quantity')), 1), 'next_7_days' => array_slice($f['forecast'], 0, 7)];
                },
            ],
            'find_product' => [
                'permission' => ['products.view', 'pos.access'],
                'description' => 'Look up products by name, SKU or barcode: price, wholesale price, stock on hand.',
                'schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
                'run' => function ($i) use ($user) {
                    $branchId = $this->branch->id();
                    $products = Product::query()->search($i['query'])->withStock($branchId, $branchId ? null : $this->branch->accessibleIds())->limit(10)->get();
                    if (! $user->hasPermission('products.view_cost')) {
                        $products->each->makeHidden(['cost_price', 'min_selling_price']);
                    }

                    return ['products' => $products->map(fn ($p) => $p->only(['id', 'name', 'sku', 'barcode', 'selling_price', 'wholesale_price', 'cost_price', 'stock', 'reorder_level', 'is_active']))->all()];
                },
            ],
            'find_customer' => [
                'permission' => 'customers.view',
                'description' => 'Find customers by name or phone: debt balance, credit limit and savings balance.',
                'schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
                'run' => fn ($i) => ['customers' => Customer::query()
                    ->where(fn ($q) => $q->where('name', 'like', '%'.$i['query'].'%')->orWhere('phone', 'like', '%'.$i['query'].'%'))
                    ->limit(10)->get(['id', 'name', 'phone', 'customer_type', 'balance', 'credit_limit'])
                    ->map(fn ($c) => $c->toArray() + ['savings_balance' => (float) (SavingsAccount::query()->where('customer_id', $c->id)->value('balance') ?? 0)])->all()],
            ],
            'customer_profile' => [
                'permission' => ['credit.view', 'savings.view', 'customers.statement'],
                'description' => 'Full money picture of one customer (by id from find_customer): savings, credit sales, repayments, overdue, and AI credit score if allowed.',
                'schema' => ['type' => 'object', 'properties' => ['customer_id' => ['type' => 'integer']], 'required' => ['customer_id']],
                'run' => function ($i) use ($user) {
                    $customer = Customer::query()->findOrFail($i['customer_id']);
                    $history = $this->customers->financialHistory($customer);
                    $history['timeline'] = array_slice($history['timeline'], -15);
                    if ($user->hasPermission('ai.credit_score')) {
                        $history['credit_score'] = $this->scorer->score($customer);
                    }

                    return $history;
                },
            ],
            'anomaly_scan' => [
                'permission' => 'ai.anomalies',
                'description' => 'Scan for possible theft, fraud or mistakes: unusual cancellations, discounts, below-cost sales, stock losses, low-sales days, late-night or backdated sales.',
                'schema' => ['type' => 'object', 'properties' => ['days' => ['type' => 'integer', 'description' => 'Look back this many days (7-90)']]],
                'run' => fn ($i) => $this->anomalies->scan($this->branch->id(), max(7, min(90, (int) ($i['days'] ?? 30)))),
            ],
            'hr_overview' => [
                'permission' => 'employees.view',
                'description' => 'Staff overview: headcount by department, who is on leave or absent today, pending leave requests, and the latest payroll totals (if allowed).',
                'schema' => ['type' => 'object', 'properties' => (object) []],
                'run' => function () use ($user) {
                    $today = today()->toDateString();
                    $scope = fn ($q) => $this->branch->canAccessAll() ? $q : $q->whereIn('branch_id', $this->branch->accessibleIds());

                    return [
                        'employed' => $scope(Employee::query()->employed())->count(),
                        'by_department' => $scope(Employee::query()->employed())->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                            ->groupBy('departments.name')->selectRaw("COALESCE(departments.name, '-') department, COUNT(*) n")->pluck('n', 'department'),
                        'on_leave_today' => Attendance::query()->whereDate('date', $today)->where('status', 'on_leave')->with('employee:id,first_name,last_name')->get()->pluck('employee.full_name'),
                        'absent_today' => Attendance::query()->whereDate('date', $today)->where('status', 'absent')->count(),
                        'pending_leave_requests' => LeaveRequest::query()->where('status', 'pending')->count(),
                        'latest_payroll' => $user->hasPermission('payroll.view')
                            ? PayrollRun::query()->where('status', '!=', PayrollRun::CANCELLED)->latest('period')->first(['number', 'period', 'status', 'employees', 'gross', 'paye', 'net', 'employer_cost'])
                            : 'not permitted',
                    ];
                },
            ],
        ];
    }
}
