<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Crm\Customer;
use App\Services\Marketing\CustomerInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/** Best, VIP, frequent, new, declining and lost customers. */
class CustomerInsightsController extends Controller
{
    public function index(Request $request, CustomerInsights $insights): JsonResponse
    {
        $f = $request->validate([
            'segment' => ['nullable', Rule::in(CustomerInsights::SEGMENTS)],
            'sort' => ['nullable', Rule::in(['spend', 'visits', 'recent', 'oldest_visit', 'lifetime'])],
            'search' => ['nullable', 'string', 'max:80'],
        ]);
        $branchIds = $this->branch()->canAccessAll() && ! $this->branch()->id() ? null : ($this->branch()->id() ? [$this->branch()->id()] : $this->branch()->accessibleIds());
        $metrics = $insights->metrics($branchIds);
        $customers = Customer::query()->whereIn('id', $metrics->keys())->get(['id', 'code', 'name', 'phone', 'email', 'balance'])->keyBy('id');

        $rows = $metrics->map(fn ($m) => $m + ['customer' => $customers->get($m['customer_id'])?->only(['id', 'code', 'name', 'phone', 'email', 'balance'])])
            ->filter(fn ($m) => $m['customer'] !== null);
        $counts = collect(CustomerInsights::SEGMENTS)->mapWithKeys(fn ($s) => [$s => $rows->filter(fn ($m) => in_array($s, $m['segments'], true))->count()]);

        if ($f['segment'] ?? null) {
            $rows = $rows->filter(fn ($m) => in_array($f['segment'], $m['segments'], true));
        }
        if ($t = mb_strtolower(trim($f['search'] ?? ''))) {
            $rows = $rows->filter(fn ($m) => str_contains(mb_strtolower($m['customer']['name'].' '.$m['customer']['phone'].' '.$m['customer']['code']), $t));
        }
        $rows = match ($f['sort'] ?? 'spend') {
            'visits' => $rows->sortByDesc('visits_90'),
            'recent' => $rows->sortByDesc('last_purchase_at'),
            'oldest_visit' => $rows->sortByDesc('days_since_last'),
            'lifetime' => $rows->sortByDesc('lifetime_spend'),
            default => $rows->sortByDesc('spend_365'),
        };

        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($rows->slice(($page - 1) * $perPage, $perPage)->values(), $rows->count(), $perPage, $page);
        $all = $metrics->filter(fn ($m) => $customers->has($m['customer_id']));
        $name = fn ($id) => $customers->get($id)?->name;

        return $this->paginated($paginator, [
            'counts' => $counts,
            'summary' => [
                'buying_customers' => $all->count(),
                'spend_365' => round((float) $all->sum('spend_365'), 2),
                'vip_share_percent' => $all->sum('spend_365') > 0 ? round($all->filter(fn ($m) => in_array('vip', $m['segments'], true))->sum('spend_365') / $all->sum('spend_365') * 100, 1) : 0,
            ],
            'best' => $all->sortByDesc('spend_365')->take(5)->map(fn ($m) => ['id' => $m['customer_id'], 'name' => $name($m['customer_id']), 'value' => $m['spend_365']])->values(),
            'most_frequent' => $all->sortByDesc('visits_90')->take(5)->map(fn ($m) => ['id' => $m['customer_id'], 'name' => $name($m['customer_id']), 'value' => $m['visits_90']])->values(),
        ]);
    }
}
