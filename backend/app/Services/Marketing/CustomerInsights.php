<?php

namespace App\Services\Marketing;

use App\Models\Crm\Customer;
use App\Services\Core\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups customers by how they buy, from their completed sales:
 *
 *   vip        top spenders of the last 12 months (top % or a minimum amount)
 *   best       spent the most in the last 12 months (the leaderboard; VIPs are a subset)
 *   frequent   many purchases in the last 90 days
 *   new        first purchase recently
 *   declining  buying clearly less often than in the 90 days before
 *   lost       bought before but not for a long time
 *   occasional everyone else who has bought
 *
 * A customer can be in several groups (a VIP who is coming less is both).
 */
class CustomerInsights
{
    public const SEGMENTS = ['vip', 'frequent', 'new', 'declining', 'lost', 'occasional'];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array<int>|null  $branchIds  only sales of these branches (null = all)
     * @return Collection<int, array> keyed by customer id
     */
    public function metrics(?array $branchIds = null, ?array $customerIds = null): Collection
    {
        $now = now();
        $d90 = $now->copy()->subDays(90);
        $d180 = $now->copy()->subDays(180);
        $d365 = $now->copy()->subDays(365);

        $rows = DB::table('sales')
            ->where('status', 'completed')->whereNotNull('customer_id')->whereNull('deleted_at')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds ?: [0]))
            ->groupBy('customer_id')
            ->selectRaw('customer_id,
                COUNT(*) as purchases,
                SUM(total - returned_amount) as lifetime_spend,
                SUM(CASE WHEN sold_at >= ? THEN total - returned_amount ELSE 0 END) as spend_365,
                SUM(CASE WHEN sold_at >= ? THEN 1 ELSE 0 END) as visits_90,
                SUM(CASE WHEN sold_at >= ? AND sold_at < ? THEN 1 ELSE 0 END) as visits_prev_90,
                MIN(sold_at) as first_at, MAX(sold_at) as last_at', [$d365, $d90, $d180, $d90])
            ->get();

        $s = $this->settings;
        $lostDays = $s->int('insights_lost_days');
        $newDays = $s->int('insights_new_days');
        $frequent = $s->int('insights_frequent_visits');
        $drop = $s->int('insights_declining_percent') / 100;

        // VIP threshold: the spend of the customer at the top-% cut, or the fixed minimum, whichever is lower.
        $spends = $rows->pluck('spend_365')->map(fn ($v) => (float) $v)->filter(fn ($v) => $v > 0)->sortDesc()->values();
        $cutIndex = max(0, (int) ceil($spends->count() * $s->int('insights_vip_top_percent') / 100) - 1);
        $topCut = $spends->get($cutIndex);
        $minSpend = (float) $s->get('insights_vip_min_spend');
        $vipLine = $minSpend > 0 ? ($topCut !== null ? min($topCut, $minSpend) : $minSpend) : $topCut;

        return $rows->mapWithKeys(function ($r) use ($now, $lostDays, $newDays, $frequent, $drop, $vipLine) {
            $last = Carbon::parse($r->last_at);
            $first = Carbon::parse($r->first_at);
            $daysSince = (int) $last->diffInDays($now);
            $spend = round((float) $r->spend_365, 2);
            $v90 = (int) $r->visits_90;
            $prev = (int) $r->visits_prev_90;

            $segments = [];
            if ($vipLine !== null && $spend > 0 && $spend >= $vipLine) {
                $segments[] = 'vip';
            }
            if ($v90 >= $frequent) {
                $segments[] = 'frequent';
            }
            if ($first->diffInDays($now) <= $newDays) {
                $segments[] = 'new';
            }
            $lost = $daysSince >= $lostDays;
            if ($lost) {
                $segments[] = 'lost';
            } elseif ($prev >= 2 && $v90 <= $prev * (1 - $drop)) {
                $segments[] = 'declining';
            }
            if ($segments === []) {
                $segments[] = 'occasional';
            }

            return [(int) $r->customer_id => [
                'customer_id' => (int) $r->customer_id,
                'purchases' => (int) $r->purchases,
                'lifetime_spend' => round((float) $r->lifetime_spend, 2),
                'spend_365' => $spend,
                'visits_90' => $v90,
                'visits_prev_90' => $prev,
                'average_basket' => (int) $r->purchases > 0 ? round((float) $r->lifetime_spend / (int) $r->purchases, 2) : 0,
                'first_purchase_at' => $first->toIso8601String(),
                'last_purchase_at' => $last->toIso8601String(),
                'days_since_last' => $daysSince,
                'segments' => $segments,
            ]];
        });
    }

    /** @return array<int, string> the groups one customer belongs to now */
    public function segmentsFor(Customer $customer): array
    {
        // Thresholds (like the VIP cut) depend on everyone, so work them out over all customers.
        return $this->metrics()->get($customer->id)['segments'] ?? [];
    }

    /** @return array<int> customer ids in any of the given groups */
    public function customersIn(array $segments, ?array $branchIds = null): array
    {
        return $this->metrics($branchIds)
            ->filter(fn ($m) => array_intersect($segments, $m['segments']) !== [])
            ->keys()->all();
    }
}
