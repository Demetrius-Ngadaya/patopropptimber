<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Services\Core\AuditLogger;
use App\Services\Core\ExportService;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $format = $request->query('export');
        if (in_array($format, ['xlsx', 'pdf'], true)) {
            $this->requirePermission($request, 'reports.export');
            $limiterKey = 'report-export:'.$request->user()->id;
            abort_if(RateLimiter::tooManyAttempts($limiterKey, 10), 429, 'Too many exports. Please wait a minute.');
            RateLimiter::hit($limiterKey, 60);
            $rows = isset($report['query']) ? $report['query']->limit(self::EXPORT_LIMIT)->get() : $report['rows'];
            $audit->event('report.exported', "Exported {$report['title']} ({$format})", null, ['filters' => $filters]);

            return $exporter->download($format, $report['title'], $report['columns'], $rows, $report['totals'] ?? [], $filters + $report['period']);
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

    private function normalizeColumns(array $columns): array
    {
        return collect($columns)->map(fn ($c, $key) => [
            'key' => $key,
            'label' => __(is_array($c) ? $c['label'] : $c),
            'type' => is_array($c) ? ($c['type'] ?? 'text') : 'text',
        ])->values()->all();
    }
}
