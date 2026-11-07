<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Account;
use App\Models\Finance\FiscalYear;
use App\Services\Finance\FinancialReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Financial statements. All read-only, computed from the general ledger. */
class FinancialReportController extends Controller
{
    public function __construct(private readonly FinancialReportService $reports) {}

    public function trialBalance(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request, false);

        return $this->ok($this->reports->trialBalance($from, $to, $this->reportBranch($request)));
    }

    public function generalLedger(Request $request, int $accountId): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->ok($this->reports->generalLedger(Account::withTrashed()->findOrFail($accountId), $from, $to, $this->reportBranch($request)));
    }

    public function profitAndLoss(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->ok($this->reports->profitAndLoss($from, $to, $this->reportBranch($request), $request->boolean('compare')));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);

        return $this->ok($this->reports->balanceSheet($request->query('as_of', now()->toDateString()), $this->reportBranch($request)));
    }

    public function cashFlow(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->ok($this->reports->cashFlow($from, $to, $this->reportBranch($request)));
    }

    public function vat(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->ok($this->reports->vat($from, $to, $this->reportBranch($request)));
    }

    public function agedReceivables(Request $request): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);

        return $this->ok($this->reports->agedReceivables($request->query('as_of', now()->toDateString()), $this->reportBranch($request)));
    }

    public function agedPayables(Request $request): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);

        return $this->ok($this->reports->agedPayables($request->query('as_of', now()->toDateString()), $this->reportBranch($request)));
    }

    public function budgetVsActual(Request $request, int $yearId): JsonResponse
    {
        return $this->ok($this->reports->budgetVsActual(FiscalYear::query()->findOrFail($yearId), $this->reportBranch($request)));
    }

    /** @return array{0: ?string, 1: string} */
    private function range(Request $request, bool $fromRequired = true): array
    {
        $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date']]);
        $to = $request->query('date_to', now()->toDateString());
        $from = $request->query('date_from', $fromRequired ? now()->startOfMonth()->toDateString() : null);
        if ($from) {
            $this->reports->assertRange($from, $to);
        }

        return [$from, $to];
    }

    /** Staff limited to some branches only ever see those branches' figures. */
    private function reportBranch(Request $request): ?int
    {
        $id = $request->integer('branch_id') ?: null;
        if ($id && $this->branch()->canAccess($id)) {
            return $id;
        }

        return $this->branch()->canAccessAll() ? null : $this->branch()->requireId();
    }
}
