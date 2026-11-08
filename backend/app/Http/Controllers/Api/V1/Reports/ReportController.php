<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Services\Core\AuditLogger;
use App\Services\Core\ExportService;
use App\Services\Reports\ReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    private const EXPORT_LIMIT = 50000;

    public function __construct(private readonly ReportService $reports) {}

    /** Reports the user may open, grouped for the menu. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $list = collect(ReportService::catalogue())
            ->filter(fn ($r) => $user->hasPermission($r['permission']))
            // In the reader's language (the catalogue is written in English).
            ->map(fn ($r, $key) => ['key' => $key, 'title' => __($r['title']), 'description' => __($r['description']), 'group' => __($r['group'])] + $r)
            ->groupBy('group');

        return $this->ok([
            'groups' => $list,
            'can_export' => $user->hasPermission('reports.export'),
        ]);
    }

    public function show(Request $request, string $key, ExportService $exporter, AuditLogger $audit): JsonResponse|Response
    {
        $definition = ReportService::catalogue()[$key] ?? abort(404);
        $this->requirePermission($request, $definition['permission']);

        $filters = $request->only($definition['filters']);
        $report = $this->reports->build($key, $filters, $request->user());
        // A total under every money / number column, for all filtered records.
        $report['totals'] = $this->allTotals($report);

        $format = $request->query('export');
        if (in_array($format, ['xlsx', 'pdf'], true)) {
            $this->requirePermission($request, 'reports.export');
            $limiterKey = 'report-export:'.$request->user()->id;
            abort_if(RateLimiter::tooManyAttempts($limiterKey, 10), 429, 'Too many exports. Please wait a minute.');
            RateLimiter::hit($limiterKey, 60);
            // scope=page: only the rows on the page being looked at (same rows-per-page);
            // otherwise everything that matches the filters.
            $onlyPage = $request->query('scope') === 'page';
            $perPage = $this->perPage($request, 50);
            $pageNo = max(1, (int) $request->integer('page', 1));
            if (isset($report['query'])) {
                $rows = $onlyPage ? $report['query']->forPage($pageNo, $perPage)->get() : $report['query']->limit(self::EXPORT_LIMIT)->get();
            } else {
                $rows = $onlyPage ? collect($report['rows'])->forPage($pageNo, $perPage)->values() : collect($report['rows']);
            }
            $first = $onlyPage ? ($pageNo - 1) * $perPage : 0;
            $rows = collect($rows)->values()->map(function ($row, $i) use ($first) {
                $n = $first + $i + 1;
                if (is_array($row)) {
                    return ['__sn' => $n] + $row;
                }
                if ($row instanceof Model) {
                    $row->setAttribute('__sn', $n);
                } elseif (is_object($row)) {
                    $row->__sn = $n;
                }

                return $row;
            });
            $totals = $onlyPage ? $this->sumRows($report['columns'], $rows) : ($report['totals'] ?? []);
            $audit->event('report.exported', "Exported {$report['title']} ({$format})", null, ['filters' => $filters, 'scope' => $onlyPage ? 'page' : 'all']);

            return $exporter->download($format, $report['title'], ['__sn' => ['label' => __('S/N'), 'type' => 'text']] + $report['columns'], $rows, $totals, $filters + (in_array('date_from', $definition['filters'], true) ? $report['period'] : []));
        }

        if (isset($report['query'])) {
            $page = $report['query']->paginate($this->perPage($request, 50));
            $data = $page->items();
            $meta = [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ];
        } else {
            $data = $report['rows']->values();
            $meta = ['current_page' => 1, 'last_page' => 1, 'per_page' => count($data), 'total' => count($data)];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => $meta,
            'report' => [
                'key' => $key,
                'title' => __($report['title']),
                'columns' => $this->normalizeColumns($report['columns']),
                'totals' => $report['totals'] ?? [],
                'summary' => $report['summary'] ?? null,
                'summary_labels' => collect($report['summary'] ?? [])->mapWithKeys(fn ($v, $k) => [$k => __(ucfirst(str_replace('_', ' ', (string) $k)))])->all(),
                'period' => $report['period'],
                'filters' => $definition['filters'],
            ],
        ]);
    }

    /** Totals for every money / number column (the report's own totals win). */
    private function allTotals(array $report): array
    {
        $totals = $report['totals'] ?? [];
        $keys = collect($report['columns'])
            ->filter(fn ($c, $key) => is_array($c) && in_array($c['type'] ?? null, ['money', 'number'], true)
                && ! array_key_exists($key, $totals) && preg_match('/^[a-z0-9_]+$/', (string) $key))
            ->keys();
        if ($keys->isEmpty()) {
            return $totals;
        }
        if (isset($report['query'])) {
            try {
                $base = $report['query'] instanceof Builder ? $report['query']->toBase() : $report['query'];
                $sums = DB::query()->fromSub((clone $base)->reorder(), 'report_rows')
                    ->selectRaw($keys->map(fn ($k) => "SUM(`{$k}`) as `{$k}`")->implode(', '))
                    ->first();
                foreach ($keys as $k) {
                    $totals[$k] = round((float) ($sums->{$k} ?? 0), 3);
                }
            } catch (\Throwable) {
                // A report whose columns cannot be summed this way keeps its own totals.
            }

            return $totals;
        }

        return $totals + $this->sumRows(collect($report['columns'])->only($keys->all())->all(), collect($report['rows']));
    }

    private function sumRows(array $columns, $rows): array
    {
        $out = [];
        foreach ($columns as $key => $c) {
            if (is_array($c) && in_array($c['type'] ?? null, ['money', 'number'], true)) {
                $out[$key] = round((float) collect($rows)->sum(fn ($r) => (float) data_get($r, $key)), 3);
            }
        }

        return $out;
    }

    private function normalizeColumns(array $columns): array
    {
        return collect($columns)->map(fn ($c, $key) => [
            'key' => $key,
            'label' => __(is_array($c) ? $c['label'] : $c),
            'type' => is_array($c) ? ($c['type'] ?? 'text') : 'text',
        ])->values()->all();
    }
}
