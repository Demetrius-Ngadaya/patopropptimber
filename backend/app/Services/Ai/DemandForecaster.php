<?php

namespace App\Services\Ai;

use App\Models\Catalog\Product;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Demand forecasting and reorder suggestions, computed in-house.
 *
 * Model: damped Holt (level + trend) on deseasonalised daily sales with a
 * day-of-week profile. Safety stock = z * sigma * sqrt(lead time), so the
 * suggestion adapts to how volatile each product's demand is.
 */
class DemandForecaster
{
    private const HISTORY_DAYS = 84;

    private const ALPHA = 0.3;

    private const BETA = 0.1;

    private const PHI = 0.9;

    /** z-scores for the service levels offered in settings. */
    private const Z = [80 => 0.84, 85 => 1.04, 90 => 1.28, 95 => 1.65, 97 => 1.88, 99 => 2.33];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Reorder table for a branch (or all branches).
     *
     * @return array<int, array<string, mixed>>
     */
    public function reorderPlan(?int $branchId, ?string $search = null, int $limit = 200): array
    {
        $today = today();
        $histories = $this->histories($branchId, null, $today);
        $onHand = $this->onHand($branchId);

        $products = Product::query()->active()->where('is_service', false)
            ->when($search, fn ($q, $s) => $q->search($s))
            ->where(fn ($q) => $q->whereIn('id', $histories->keys())->orWhereIn('id', $onHand->keys()))
            ->with('unit:id,short_name')
            ->get(['id', 'name', 'sku', 'unit_id', 'cost_price', 'selling_price', 'reorder_level']);

        $lead = $this->settings->int('ai_forecast_lead_days');
        $cover = $this->settings->int('ai_forecast_cover_days');
        $z = self::Z[$this->settings->int('ai_service_level')] ?? 1.65;

        $rows = [];
        foreach ($products as $product) {
            $series = $this->series($histories->get($product->id, collect()), $today);
            $model = $this->fit($series, $today);
            $stock = (float) ($onHand->get($product->id) ?? 0);

            $leadDemand = $this->sumForecast($model, $today, $lead);
            $coverDemand = $this->sumForecast($model, $today->copy()->addDays($lead), $cover);
            $safety = round($z * $model['sigma'] * sqrt($lead), 2);
            $reorderPoint = max((float) $product->reorder_level, round($leadDemand + $safety, 2));
            $daily = $model['daily'];
            $daysOfCover = $daily > 0.0001 ? round($stock / $daily, 1) : null;
            $lastSale = $model['last_sale'];

            $status = match (true) {
                $stock <= 0 && $daily > 0 => 'out_of_stock',
                $stock <= $reorderPoint && $daily > 0 => 'reorder_now',
                $daysOfCover !== null && $daysOfCover <= $lead + 3 => 'reorder_soon',
                $stock > 0 && ($lastSale === null || $lastSale->diffInDays($today) >= 60) => 'dead_stock',
                $daysOfCover !== null && $daysOfCover > 120 => 'overstock',
                default => 'ok',
            };
            $suggested = in_array($status, ['out_of_stock', 'reorder_now', 'reorder_soon'], true)
                ? max(0, (int) ceil($reorderPoint + $coverDemand - $stock))
                : 0;

            $rows[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unit?->short_name,
                'on_hand' => round($stock, 3),
                'avg_daily_sales' => round($model['history_avg'], 2),
                'forecast_daily' => round($daily, 2),
                'forecast_30_days' => round($this->sumForecast($model, $today, 30), 1),
                'trend' => $model['trend_label'],
                'days_of_cover' => $daysOfCover,
                'reorder_point' => round($reorderPoint, 1),
                'safety_stock' => $safety,
                'suggested_order' => $suggested,
                'suggested_order_cost' => round($suggested * (float) $product->cost_price, 2),
                'stockout_date' => $daysOfCover !== null && $daysOfCover < 365 ? $today->copy()->addDays((int) floor($daysOfCover))->toDateString() : null,
                'last_sale' => $lastSale?->toDateString(),
                'stock_value' => round($stock * (float) $product->cost_price, 2),
                'confidence' => $model['confidence'],
                'status' => $status,
            ];
        }

        $priority = ['out_of_stock' => 0, 'reorder_now' => 1, 'reorder_soon' => 2, 'dead_stock' => 3, 'overstock' => 4, 'ok' => 5];
        usort($rows, fn ($a, $b) => [$priority[$a['status']], $a['days_of_cover'] ?? INF] <=> [$priority[$b['status']], $b['days_of_cover'] ?? INF]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * History and forecast of one product, for a chart.
     *
     * @return array{history: array, forecast: array, summary: array}
     */
    public function productForecast(Product $product, ?int $branchId, int $horizon = 30): array
    {
        $today = today();
        $series = $this->series($this->histories($branchId, [$product->id], $today)->get($product->id, collect()), $today);
        $model = $this->fit($series, $today);

        $forecast = [];
        for ($h = 1; $h <= $horizon; $h++) {
            $day = $today->copy()->addDays($h - 1);
            $value = $this->point($model, $h, $day);
            $band = 1.28 * $model['sigma'] * sqrt(min($h, 14));
            $forecast[] = ['date' => $day->toDateString(), 'quantity' => round($value, 2), 'low' => round(max(0, $value - $band), 2), 'high' => round($value + $band, 2)];
        }

        return [
            'history' => collect($series)->map(fn ($q, $d) => ['date' => $d, 'quantity' => $q])->values()->all(),
            'forecast' => $forecast,
            'summary' => [
                'avg_daily_sales' => round($model['history_avg'], 2),
                'forecast_daily' => round($model['daily'], 2),
                'trend' => $model['trend_label'],
                'weekday_profile' => $model['weekday'],
                'confidence' => $model['confidence'],
            ],
        ];
    }

    // ------------------------------------------------------------------ model

    /** @return Collection<int, Collection<string, float>> product_id => [date => quantity] */
    private function histories(?int $branchId, ?array $productIds, Carbon $today): Collection
    {
        $from = $today->copy()->subDays(self::HISTORY_DAYS)->startOfDay();

        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereNull('sales.deleted_at')
            ->where('sales.sold_at', '>=', $from)
            ->where('sales.sold_at', '<', $today->copy()->startOfDay())
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->when($productIds, fn ($q) => $q->whereIn('sale_items.product_id', $productIds))
            ->groupBy('sale_items.product_id', DB::raw('DATE(sales.sold_at)'))
            ->selectRaw('sale_items.product_id, DATE(sales.sold_at) as day, SUM(sale_items.quantity - sale_items.returned_quantity) as qty')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [substr((string) $r->day, 0, 10) => max(0, (float) $r->qty)]));
    }

    /** @return Collection<int, float> */
    private function onHand(?int $branchId): Collection
    {
        return DB::table('product_stocks')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (float) $q);
    }

    /** Zero-filled daily series, oldest first. */
    private function series(Collection $history, Carbon $today): array
    {
        $series = [];
        for ($d = self::HISTORY_DAYS; $d >= 1; $d--) {
            $date = $today->copy()->subDays($d)->toDateString();
            $series[$date] = (float) ($history[$date] ?? 0);
        }

        return $series;
    }

    private function fit(array $series, Carbon $today): array
    {
        $values = array_values($series);
        $dates = array_keys($series);
        $n = count($values);
        $mean = $n ? array_sum($values) / $n : 0;
        $nonZero = count(array_filter($values, fn ($v) => $v > 0));
        $lastSaleIndex = null;
        foreach ($values as $i => $v) {
            if ($v > 0) {
                $lastSaleIndex = $i;
            }
        }

        // Day-of-week profile (needs some history to be meaningful).
        $weekday = array_fill(0, 7, 1.0);
        if ($mean > 0 && $nonZero >= 14) {
            $sums = array_fill(0, 7, 0.0);
            $counts = array_fill(0, 7, 0);
            foreach ($dates as $i => $date) {
                $dow = Carbon::parse($date)->dayOfWeek;
                $sums[$dow] += $values[$i];
                $counts[$dow]++;
            }
            for ($d = 0; $d < 7; $d++) {
                // Shrink towards 1 so a single busy Saturday does not dominate.
                $raw = $counts[$d] ? ($sums[$d] / $counts[$d]) / $mean : 1;
                $weekday[$d] = round(0.7 * $raw + 0.3, 3);
            }
        }

        // Damped Holt on deseasonalised values.
        $level = $mean;
        $trend = 0.0;
        $errors = [];
        foreach ($values as $i => $v) {
            $factor = max(0.05, $weekday[Carbon::parse($dates[$i])->dayOfWeek]);
            $predicted = ($level + self::PHI * $trend) * $factor;
            if ($i >= 14) {
                $errors[] = $v - $predicted;
            }
            $x = $v / $factor;
            $prevLevel = $level;
            $level = self::ALPHA * $x + (1 - self::ALPHA) * ($level + self::PHI * $trend);
            $trend = self::BETA * ($level - $prevLevel) + (1 - self::BETA) * self::PHI * $trend;
        }
        $level = max(0, $level);
        $sigma = $errors ? sqrt(array_sum(array_map(fn ($e) => $e * $e, $errors)) / count($errors)) : $mean;

        $recent = array_slice($values, -28);
        $previous = array_slice($values, -56, 28);
        $recentAvg = $recent ? array_sum($recent) / count($recent) : 0;
        $previousAvg = $previous ? array_sum($previous) / count($previous) : 0;
        $change = $previousAvg > 0 ? ($recentAvg - $previousAvg) / $previousAvg : ($recentAvg > 0 ? 1 : 0);

        return [
            'level' => $level,
            'trend' => $trend,
            'weekday' => $weekday,
            'sigma' => round($sigma, 4),
            'daily' => max(0, $level + $trend * self::PHI),
            'history_avg' => $mean,
            'trend_label' => $change > 0.15 ? 'rising' : ($change < -0.15 ? 'falling' : 'steady'),
            'confidence' => $nonZero >= 40 ? 'high' : ($nonZero >= 12 ? 'medium' : 'low'),
            'last_sale' => $lastSaleIndex !== null ? Carbon::parse($dates[$lastSaleIndex]) : null,
        ];
    }

    private function point(array $model, int $h, Carbon $day): float
    {
        // Damped trend: sum of phi^1..phi^h.
        $damp = 0.0;
        for ($k = 1; $k <= $h; $k++) {
            $damp += self::PHI ** $k;
        }

        return max(0, ($model['level'] + $damp * $model['trend']) * $model['weekday'][$day->dayOfWeek]);
    }

    private function sumForecast(array $model, Carbon $start, int $days): float
    {
        $total = 0.0;
        for ($h = 1; $h <= $days; $h++) {
            $total += $this->point($model, $h, $start->copy()->addDays($h - 1));
        }

        return $total;
    }
}
