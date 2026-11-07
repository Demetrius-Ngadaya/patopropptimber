<?php

namespace App\Services\Ai;

use App\Models\Finance\Expense;
use App\Models\Sales\Sale;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Plain-language insights for the dashboard. The facts are computed here
 * (they work without any AI key); when a language model is configured it adds a short
 * written summary of the same facts in the user's language.
 */
class InsightService
{
    public function __construct(
        private readonly DemandForecaster $forecaster,
        private readonly AnomalyDetector $anomalies,
        private readonly AiClient $ai,
    ) {}

    /** @return array{metrics: array, insights: array<int, array>, narrative: ?string, ai_available: bool} */
    public function generate(?int $branchId, bool $withNarrative = true): array
    {
        $metrics = $this->metrics($branchId);
        $insights = $this->rules($metrics);
        $narrative = null;
        if ($withNarrative && $this->ai->configured()) {
            $narrative = $this->narrative($metrics, $insights, $branchId);
        }

        return ['metrics' => $metrics, 'insights' => $insights, 'narrative' => $narrative, 'ai_available' => $this->ai->configured()];
    }

    public function metrics(?int $branchId): array
    {
        $today = today();
        $thisWeek = [$today->copy()->subDays(6)->startOfDay(), now()];
        $lastWeek = [$today->copy()->subDays(13)->startOfDay(), $today->copy()->subDays(7)->endOfDay()];

        $week = fn (array $range) => Sale::query()->completed()->whereBetween('sold_at', $range)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(total),0) revenue, COALESCE(SUM(cost_total),0) cost')->first();
        $now = $week($thisWeek);
        $prev = $week($lastWeek);

        $products = fn (array $range) => DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)->whereNull('sales.deleted_at')
            ->whereBetween('sales.sold_at', $range)
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->selectRaw('sale_items.product_id, sale_items.product_name name, SUM(sale_items.line_total) revenue')
            ->pluck('revenue', 'name')->map(fn ($v) => (float) $v);
        $pNow = $products($thisWeek);
        $pPrev = $products($lastWeek);
        $movers = $pNow->keys()->merge($pPrev->keys())->unique()
            ->map(fn ($name) => ['name' => $name, 'change' => round(($pNow[$name] ?? 0) - ($pPrev[$name] ?? 0), 2)])
            ->sortByDesc('change')->values();

        $plan = collect($this->forecaster->reorderPlan($branchId, null, 1000));
        $overdue = Sale::query()->completed()->where('balance', '>', 0)->whereDate('due_date', '<', $today)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(balance),0) amount')->first();
        $dueSoon = Sale::query()->completed()->where('balance', '>', 0)->whereBetween('due_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(balance),0) amount')->first();
        $expenses = (float) Expense::query()->whereBetween('expense_date', [$thisWeek[0]->toDateString(), $today->toDateString()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('amount');
        $scan = $this->anomalies->scan($branchId, 14);

        $revenue = (float) $now->revenue;
        $prevRevenue = (float) $prev->revenue;

        return [
            'revenue' => round($revenue, 2),
            'revenue_previous' => round($prevRevenue, 2),
            'revenue_change_pct' => $prevRevenue > 0 ? round(($revenue - $prevRevenue) / $prevRevenue * 100, 1) : null,
            'transactions' => (int) $now->n,
            'transactions_previous' => (int) $prev->n,
            'average_sale' => $now->n ? round($revenue / $now->n, 2) : 0,
            'gross_margin_pct' => $revenue > 0 ? round(($revenue - (float) $now->cost) / $revenue * 100, 1) : null,
            'gross_margin_previous_pct' => $prevRevenue > 0 ? round(($prevRevenue - (float) $prev->cost) / $prevRevenue * 100, 1) : null,
            'expenses' => round($expenses, 2),
            'top_rising' => $movers->first(fn ($m) => $m['change'] > 0),
            'top_falling' => $movers->reverse()->first(fn ($m) => $m['change'] < 0),
            'stockouts' => $plan->where('status', 'out_of_stock')->count(),
            'reorder_now' => $plan->where('status', 'reorder_now')->count(),
            'reorder_items' => $plan->whereIn('status', ['out_of_stock', 'reorder_now'])->take(5)->pluck('name')->values()->all(),
            'reorder_cost' => round((float) $plan->sum('suggested_order_cost'), 2),
            'dead_stock_items' => $plan->where('status', 'dead_stock')->count(),
            'dead_stock_value' => round((float) $plan->where('status', 'dead_stock')->sum('stock_value'), 2),
            'overdue_debts' => (int) $overdue->n,
            'overdue_amount' => round((float) $overdue->amount, 2),
            'due_this_week' => (int) $dueSoon->n,
            'due_this_week_amount' => round((float) $dueSoon->amount, 2),
            'anomalies_high' => $scan['summary']['high'],
            'anomalies_medium' => $scan['summary']['medium'],
            'period' => ['from' => $thisWeek[0]->toDateString(), 'to' => $today->toDateString()],
        ];
    }

    /** Deterministic insights from the metrics. */
    private function rules(array $m): array
    {
        $out = [];
        $money = fn ($v) => number_format((float) $v, 0);

        if ($m['revenue_change_pct'] !== null && abs($m['revenue_change_pct']) >= 10) {
            $up = $m['revenue_change_pct'] > 0;
            $out[] = $this->insight('revenue_trend', $up ? 'positive' : 'warning',
                $up ? __('Sales are up :pct% this week', ['pct' => $m['revenue_change_pct']]) : __('Sales are down :pct% this week', ['pct' => abs($m['revenue_change_pct'])]),
                __(':now this week against :prev last week.', ['now' => $money($m['revenue']), 'prev' => $money($m['revenue_previous'])]), '/reports/sales');
        }
        if ($m['gross_margin_pct'] !== null && $m['gross_margin_previous_pct'] !== null && $m['gross_margin_pct'] < $m['gross_margin_previous_pct'] - 3) {
            $out[] = $this->insight('margin_drop', 'warning', __('Profit margin is falling'),
                __('Gross margin is :now% this week, down from :prev%. Check discounts and recent cost increases.', ['now' => $m['gross_margin_pct'], 'prev' => $m['gross_margin_previous_pct']]), '/reports/profit');
        }
        if ($m['stockouts'] + $m['reorder_now'] > 0) {
            $out[] = $this->insight('reorder', $m['stockouts'] > 0 ? 'critical' : 'warning',
                __(':n product(s) need reordering', ['n' => $m['stockouts'] + $m['reorder_now']]),
                __('Including :items. Suggested order cost: :cost.', ['items' => implode(', ', $m['reorder_items']), 'cost' => $money($m['reorder_cost'])]), '/ai/reorder');
        }
        if ($m['dead_stock_items'] > 0 && $m['dead_stock_value'] > 0) {
            $out[] = $this->insight('dead_stock', 'info', __(':n product(s) have not sold in 60 days', ['n' => $m['dead_stock_items']]),
                __(':value is tied up in slow stock. Consider a promotion or a transfer to another branch.', ['value' => $money($m['dead_stock_value'])]), '/ai/reorder?status=dead_stock');
        }
        if ($m['overdue_debts'] > 0) {
            $out[] = $this->insight('overdue', 'warning', __(':n credit sale(s) are overdue', ['n' => $m['overdue_debts']]),
                __(':amount should already have been repaid. Send reminders from Credit sales.', ['amount' => $money($m['overdue_amount'])]), '/credit-sales?status=overdue');
        }
        if ($m['due_this_week'] > 0) {
            $out[] = $this->insight('due_soon', 'info', __(':n repayment(s) due this week', ['n' => $m['due_this_week']]),
                __('Expected: :amount.', ['amount' => $money($m['due_this_week_amount'])]), '/credit-sales?status=outstanding');
        }
        if ($m['top_rising']) {
            $out[] = $this->insight('top_rising', 'positive', __(':name is selling more', ['name' => $m['top_rising']['name']]),
                __('Up :amount compared with last week. Make sure it stays in stock.', ['amount' => $money($m['top_rising']['change'])]), '/reports/performance');
        }
        if ($m['top_falling']) {
            $out[] = $this->insight('top_falling', 'info', __(':name is selling less', ['name' => $m['top_falling']['name']]),
                __('Down :amount compared with last week.', ['amount' => $money(abs($m['top_falling']['change']))]), '/reports/performance');
        }
        if ($m['revenue'] > 0 && $m['expenses'] > $m['revenue'] * 0.3) {
            $out[] = $this->insight('expenses', 'warning', __('Expenses are high'),
                __('Expenses this week are :pct% of sales.', ['pct' => round($m['expenses'] / $m['revenue'] * 100)]), '/expenses');
        }
        if ($m['anomalies_high'] > 0) {
            $out[] = $this->insight('anomalies', 'critical', __(':n serious alert(s) need review', ['n' => $m['anomalies_high']]),
                __('Unusual cancellations, discounts or losses were detected.'), '/ai/anomalies');
        }

        return $out;
    }

    private function narrative(array $metrics, array $insights, ?int $branchId): ?string
    {
        $locale = App::getLocale();
        $key = 'ai.narrative.'.($branchId ?? 'all').'.'.$locale.'.'.md5(json_encode($metrics));

        try {
            return Cache::remember($key, now()->addHours(3), fn () => $this->ai->text(
                'insights',
                'You are a sharp, practical business advisor for a small or medium shop in East Africa. '.
                'Write for the owner. Be concrete, use the numbers given, never invent numbers. '.
                'Answer in '.($locale === 'sw' ? 'Kiswahili' : 'English').'. '.
                'Give 3 to 5 short bullet points, most important first, each with one clear action. No preamble.',
                "This week's business figures (JSON):\n".json_encode(['metrics' => $metrics, 'rule_based_findings' => array_column($insights, 'title')], JSON_UNESCAPED_UNICODE),
                fast: true,
                maxTokens: 600,
            ));
        } catch (Throwable) {
            return null; // insights still work without the narrative
        }
    }

    private function insight(string $key, string $type, string $title, string $detail, string $link): array
    {
        return compact('key', 'type', 'title', 'detail', 'link');
    }
}
