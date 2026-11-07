<?php

namespace App\Services\Ai;

use App\Models\Sales\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finds patterns worth a manager's attention: possible theft, fraud or
 * mistakes. Uses robust statistics (median and MAD), so one busy day or one
 * very active cashier does not hide or create alerts.
 */
class AnomalyDetector
{
    /**
     * @return array{period: array, anomalies: array<int, array<string, mixed>>, summary: array}
     */
    public function scan(?int $branchId, int $days = 30): array
    {
        $to = now();
        $from = now()->subDays($days)->startOfDay();

        $anomalies = array_merge(
            $this->cashierCancellations($branchId, $from, $to),
            $this->cashierDiscounts($branchId, $from, $to),
            $this->belowCostSales($branchId, $from, $to),
            $this->stockLosses($branchId, $from, $to),
            $this->dailyRevenue($branchId, $from),
            $this->unusualTimes($branchId, $from, $to),
            $this->backdatedSales($branchId, $from, $to),
        );

        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($anomalies, fn ($a, $b) => [$rank[$a['severity']], -($a['amount'] ?? 0)] <=> [$rank[$b['severity']], -($b['amount'] ?? 0)]);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'anomalies' => $anomalies,
            'summary' => [
                'high' => count(array_filter($anomalies, fn ($a) => $a['severity'] === 'high')),
                'medium' => count(array_filter($anomalies, fn ($a) => $a['severity'] === 'medium')),
                'low' => count(array_filter($anomalies, fn ($a) => $a['severity'] === 'low')),
            ],
        ];
    }

    // ------------------------------------------------------------------ rules

    private function cashierCancellations(?int $branchId, Carbon $from, Carbon $to): array
    {
        $rows = $this->sales($branchId, $from, $to)
            ->join('users', 'users.id', '=', 'sales.cashier_id')
            ->groupBy('sales.cashier_id', 'users.name')
            ->selectRaw("sales.cashier_id, users.name, COUNT(*) total, SUM(CASE WHEN sales.status = 'cancelled' THEN 1 ELSE 0 END) cancelled,
                SUM(CASE WHEN sales.status = 'cancelled' THEN sales.total ELSE 0 END) cancelled_value")
            ->get();
        $allTotal = (int) $rows->sum('total');
        $allCancelled = (int) $rows->sum('cancelled');

        $out = [];
        foreach ($rows as $r) {
            $rate = $r->total ? $r->cancelled / $r->total : 0;
            // Compare each cashier with everyone else, not with a group that includes them.
            $othersTotal = $allTotal - $r->total;
            $baseline = $othersTotal > 0 ? ($allCancelled - $r->cancelled) / $othersTotal : 0.0;
            if ($r->cancelled >= 3 && $rate > max(0.05, $baseline * 3)) {
                $out[] = $this->anomaly('cancellations', $rate > 0.15 ? 'high' : 'medium',
                    __(':name cancels many sales', ['name' => $r->name]),
                    __(':cancelled of :total sales cancelled (:rate%), the usual rate is :base%. Cancelled value: :value.', [
                        'cancelled' => $r->cancelled, 'total' => $r->total, 'rate' => round($rate * 100, 1),
                        'base' => round($baseline * 100, 1), 'value' => number_format((float) $r->cancelled_value, 2),
                    ]),
                    ['type' => 'user', 'id' => $r->cashier_id, 'name' => $r->name],
                    (float) $r->cancelled_value,
                    __('Review the cancelled sales and their reasons in the audit log.'));
            }
        }

        return $out;
    }

    private function cashierDiscounts(?int $branchId, Carbon $from, Carbon $to): array
    {
        $rows = $this->sales($branchId, $from, $to)->where('sales.status', Sale::STATUS_COMPLETED)
            ->join('users', 'users.id', '=', 'sales.cashier_id')
            ->groupBy('sales.cashier_id', 'users.name')
            ->selectRaw('sales.cashier_id, users.name, COUNT(*) n, SUM(sales.subtotal) gross, SUM(sales.discount + sales.item_discount) disc')
            ->get()->filter(fn ($r) => $r->n >= 5 && (float) $r->gross > 0);
        if ($rows->count() < 2) {
            return [];
        }
        $rates = $rows->map(fn ($r) => (float) $r->disc / (float) $r->gross)->values()->all();
        [$median, $mad] = [$this->median($rates), $this->mad($rates)];

        $out = [];
        foreach ($rows as $r) {
            $rate = (float) $r->disc / (float) $r->gross;
            $z = $this->robustZ($rate, $median, $mad);
            if ($rate > 0.05 && $z > 3.5) {
                $out[] = $this->anomaly('discounts', $rate > 0.15 ? 'high' : 'medium',
                    __(':name gives unusually large discounts', ['name' => $r->name]),
                    __('Discounts are :rate% of sales, colleagues average :base%. Total discounted: :value.', [
                        'rate' => round($rate * 100, 1), 'base' => round($median * 100, 1), 'value' => number_format((float) $r->disc, 2),
                    ]),
                    ['type' => 'user', 'id' => $r->cashier_id, 'name' => $r->name],
                    (float) $r->disc,
                    __('Check whether these discounts were authorised.'));
            }
        }

        return $out;
    }

    private function belowCostSales(?int $branchId, Carbon $from, Carbon $to): array
    {
        $rows = $this->sales($branchId, $from, $to)->where('sales.status', Sale::STATUS_COMPLETED)
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('users', 'users.id', '=', 'sales.cashier_id')
            ->whereRaw('sale_items.line_total < sale_items.unit_cost * sale_items.quantity - 0.01')
            ->groupBy('sales.cashier_id', 'users.name')
            ->selectRaw('sales.cashier_id, users.name, COUNT(*) line_count, SUM(sale_items.unit_cost * sale_items.quantity - sale_items.line_total) loss')
            ->get();

        return $rows->map(fn ($r) => $this->anomaly('below_cost', (float) $r->loss > 100000 ? 'high' : 'medium',
            __(':name sold items below cost', ['name' => $r->name]),
            __(':lines item line(s) sold below cost, a loss of :loss.', ['lines' => $r->line_count, 'loss' => number_format((float) $r->loss, 2)]),
            ['type' => 'user', 'id' => $r->cashier_id, 'name' => $r->name],
            (float) $r->loss,
            __('Set minimum selling prices and check who may change prices.')))->all();
    }

    private function stockLosses(?int $branchId, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('stock_movements')
            ->leftJoin('users', 'users.id', '=', 'stock_movements.user_id')
            ->whereIn('stock_movements.type', ['damage', 'expired', 'adjustment_out'])
            ->whereBetween('stock_movements.created_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('stock_movements.branch_id', $branchId))
            ->groupBy('stock_movements.user_id', 'users.name')
            ->selectRaw('stock_movements.user_id, users.name, COUNT(*) n, SUM(-stock_movements.quantity * stock_movements.unit_cost) loss_value')
            ->get();
        $total = (float) $rows->sum('loss_value');
        if ($total <= 0) {
            return [];
        }
        $sales = (float) $this->sales($branchId, $from, $to)->where('sales.status', Sale::STATUS_COMPLETED)->sum('sales.cost_total');

        $out = [];
        if ($sales > 0 && $total / $sales > 0.02) {
            $out[] = $this->anomaly('stock_loss', $total / $sales > 0.05 ? 'high' : 'medium',
                __('High stock losses'),
                __('Damaged, expired and written-off stock worth :value is :rate% of the cost of goods sold.', ['value' => number_format($total, 2), 'rate' => round($total / $sales * 100, 1)]),
                ['type' => 'branch', 'id' => $branchId],
                $total,
                __('Do a stock count and review who records adjustments.'));
        }
        foreach ($rows as $r) {
            if ($rows->count() >= 2 && (float) $r->loss_value / $total > 0.6 && (float) $r->loss_value > 50000) {
                $out[] = $this->anomaly('stock_loss_user', 'medium',
                    __('Most stock write-offs come from :name', ['name' => $r->name ?? __('Unknown')]),
                    __(':n write-off(s) worth :value (:share% of all losses).', ['n' => $r->n, 'value' => number_format((float) $r->loss_value, 2), 'share' => round((float) $r->loss_value / $total * 100)]),
                    ['type' => 'user', 'id' => $r->user_id, 'name' => $r->name],
                    (float) $r->loss_value,
                    __('Ask for evidence (photos, expired items) before approving write-offs.'));
            }
        }

        return $out;
    }

    private function dailyRevenue(?int $branchId, Carbon $from): array
    {
        $history = $this->sales($branchId, now()->subDays(90)->startOfDay(), now()->startOfDay())
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->groupBy(DB::raw('DATE(sales.sold_at)'))
            ->selectRaw('DATE(sales.sold_at) d, SUM(sales.total) t')
            ->pluck('t', 'd')->map(fn ($v) => (float) $v);
        if ($history->count() < 21) {
            return [];
        }

        $byWeekday = [];
        foreach ($history as $day => $total) {
            $byWeekday[Carbon::parse($day)->dayOfWeek][] = $total;
        }

        $out = [];
        foreach ($history as $day => $total) {
            if ($day < $from->toDateString()) {
                continue;
            }
            $peers = $byWeekday[Carbon::parse($day)->dayOfWeek] ?? [];
            if (count($peers) < 4) {
                continue;
            }
            $median = $this->median($peers);
            $z = $this->robustZ($total, $median, $this->mad($peers));
            if ($z < -3.5 && $total < $median * 0.5) {
                $out[] = $this->anomaly('low_revenue', 'medium',
                    __('Unusually low sales on :date', ['date' => Carbon::parse($day)->format('d/m/Y')]),
                    __('Sales were :total against a usual :median for that weekday.', ['total' => number_format($total, 2), 'median' => number_format($median, 2)]),
                    ['type' => 'date', 'id' => $day],
                    $median - $total,
                    __('Check whether all sales were recorded and the cash was banked.'));
            }
        }

        return $out;
    }

    private function unusualTimes(?int $branchId, Carbon $from, Carbon $to): array
    {
        $hour = DB::connection()->getDriverName() === 'sqlite' ? "CAST(strftime('%H', sales.sold_at) AS INTEGER)" : 'HOUR(sales.sold_at)';
        $row = $this->sales($branchId, $from, $to)->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereRaw("({$hour} < 5 OR {$hour} >= 23)")
            ->selectRaw('COUNT(*) n, COALESCE(SUM(sales.total),0) t')->first();
        if (! $row || $row->n < 1) {
            return [];
        }

        return [$this->anomaly('after_hours', $row->n >= 5 ? 'medium' : 'low',
            __('Sales recorded late at night'),
            __(':n sale(s) worth :value were recorded between 23:00 and 05:00.', ['n' => $row->n, 'value' => number_format((float) $row->t, 2)]),
            ['type' => 'branch', 'id' => $branchId],
            (float) $row->t,
            __('Confirm the shop was open, or check the device clock.'))];
    }

    private function backdatedSales(?int $branchId, Carbon $from, Carbon $to): array
    {
        $rows = $this->sales($branchId, $from, $to)
            ->join('users', 'users.id', '=', 'sales.cashier_id')
            ->get(['sales.cashier_id', 'users.name', 'sales.total', 'sales.sold_at', 'sales.created_at'])
            ->filter(fn ($r) => Carbon::parse($r->created_at)->diffInHours(Carbon::parse($r->sold_at), true) > 24)
            ->groupBy('cashier_id');

        return $rows->map(fn ($group) => $this->anomaly('backdated', $group->count() >= 5 ? 'medium' : 'low',
            __(':name recorded sales on earlier dates', ['name' => $group->first()->name]),
            __(':n sale(s) worth :value were dated more than a day before they were entered.', ['n' => $group->count(), 'value' => number_format((float) $group->sum('total'), 2)]),
            ['type' => 'user', 'id' => $group->first()->cashier_id, 'name' => $group->first()->name],
            (float) $group->sum('total'),
            __('Backdating should be rare; review the permission.')))->values()->all();
    }

    // ---------------------------------------------------------------- helpers

    private function sales(?int $branchId, Carbon $from, Carbon $to)
    {
        return DB::table('sales')->whereNull('sales.deleted_at')
            ->whereBetween('sales.sold_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId));
    }

    private function anomaly(string $type, string $severity, string $title, string $detail, array $subject, float $amount, string $action): array
    {
        return compact('type', 'severity', 'title', 'detail', 'subject', 'action') + ['amount' => round($amount, 2)];
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private function mad(array $values): float
    {
        $median = $this->median($values);

        return $this->median(array_map(fn ($v) => abs($v - $median), $values));
    }

    private function robustZ(float $x, float $median, float $mad): float
    {
        if ($mad < 1e-9) {
            return $x == $median ? 0.0 : ($x > $median ? 10.0 : -10.0);
        }

        return ($x - $median) / (1.4826 * $mad);
    }
}
