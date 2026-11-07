<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', now()->year);
        $year = max(2000, min($year, (int) now()->year + 1));

        return $this->ok($this->dashboard->overview($request->user(), $year));
    }

    /** Business analysis: totals for today, week, month, quarters, halves, year, all time. */
    public function periods(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'dashboard.view_financials');
        $profit = $request->user()->hasAnyPermission(['sales.view_profit', 'reports.profit']);

        return $this->ok($this->dashboard->periods($profit));
    }
}
