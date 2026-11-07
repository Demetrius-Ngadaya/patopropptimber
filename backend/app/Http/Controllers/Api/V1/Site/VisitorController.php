<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Models\Site\SiteVisitor;
use App\Models\Site\SiteVisitorDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Website visitors for the back office: who is online now, and how many came today, this week... */
class VisitorController extends Controller
{
    public function summary(): JsonResponse
    {
        $today = today();
        $unique = fn (Carbon $from) => SiteVisitorDay::query()->where('day', '>=', $from->toDateString())->distinct()->count('site_visitor_id');
        $onlineSince = now()->subMinutes(SiteVisitor::ONLINE_MINUTES);
        $from30 = $today->copy()->subDays(29);

        $daily = SiteVisitorDay::query()->where('day', '>=', $from30->toDateString())
            ->groupBy('day')->orderBy('day')
            ->get([DB::raw('day'), DB::raw('COUNT(*) as visitors'), DB::raw('SUM(page_views) as page_views')])
            ->keyBy(fn ($r) => Carbon::parse($r->day)->toDateString());
        $series = collect(range(0, 29))->map(function ($i) use ($from30, $daily) {
            $d = $from30->copy()->addDays($i)->toDateString();

            return ['day' => $d, 'visitors' => (int) ($daily[$d]->visitors ?? 0), 'page_views' => (int) ($daily[$d]->page_views ?? 0)];
        });
        $recent = SiteVisitor::query()->where('last_seen_at', '>=', $from30);
        $group = fn (string $column, int $limit = 8) => (clone $recent)->whereNotNull($column)->groupBy($column)
            ->orderByDesc('total')->limit($limit)->get([$column.' as name', DB::raw('COUNT(*) as total')]);

        return $this->ok([
            'online_now' => SiteVisitor::query()->where('last_seen_at', '>=', $onlineSince)->count(),
            'online_by_kind' => SiteVisitor::query()->where('last_seen_at', '>=', $onlineSince)->groupBy('kind')->get(['kind', DB::raw('COUNT(*) as total')])->pluck('total', 'kind'),
            'today' => $unique($today),
            'yesterday' => SiteVisitorDay::query()->where('day', $today->copy()->subDay()->toDateString())->count(),
            'this_week' => $unique($today->copy()->startOfWeek()),
            'this_month' => $unique($today->copy()->startOfMonth()),
            'this_year' => $unique($today->copy()->startOfYear()),
            'all_time' => SiteVisitor::query()->count(),
            'new_today' => SiteVisitor::query()->where('first_seen_at', '>=', $today)->count(),
            'page_views_today' => (int) SiteVisitorDay::query()->where('day', $today->toDateString())->sum('page_views'),
            'daily' => $series,
            'countries' => $group('country'),
            'cities' => $group('city'),
            'devices' => $group('device', 3),
            'browsers' => $group('browser', 6),
            'referrers' => $group('referrer', 6),
            'top_pages' => DB::table('site_page_stats')->where('day', '>=', $today->copy()->subDays(6)->toDateString())
                ->groupBy('path')->orderByDesc('views')->limit(10)->get(['path', DB::raw('SUM(views) as views')]),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'period' => ['nullable', 'in:online,today,week,month,year,all'],
            'kind' => ['nullable', 'in:guest,customer,staff'],
        ]);
        $from = match ($request->query('period', 'today')) {
            'online' => now()->subMinutes(SiteVisitor::ONLINE_MINUTES),
            'today' => today(),
            'week' => today()->startOfWeek(),
            'month' => today()->startOfMonth(),
            'year' => today()->startOfYear(),
            default => null,
        };
        $query = SiteVisitor::query()->with('user:id,name,username,type')
            ->when($from, fn (Builder $q) => $q->where('last_seen_at', '>=', $from))
            ->when($request->query('kind'), fn (Builder $q, $k) => $q->where('kind', $k))
            ->when($request->query('search'), function (Builder $q, $term) {
                $q->where(fn (Builder $w) => $w->where('city', 'like', "%{$term}%")->orWhere('region', 'like', "%{$term}%")
                    ->orWhere('country', $term)->orWhere('ip', 'like', "{$term}%")->orWhere('current_path', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")));
            })
            ->latest('last_seen_at');

        return $this->paginated($query->paginate($this->perPage($request)));
    }
}
