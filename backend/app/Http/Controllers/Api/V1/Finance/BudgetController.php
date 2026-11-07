<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Finance\Budget;
use App\Models\Finance\FiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Monthly budgets per account (and optionally per branch) for a financial year. */
class BudgetController extends Controller
{
    public function index(Request $request, int $yearId): JsonResponse
    {
        $year = FiscalYear::query()->findOrFail($yearId);

        return $this->ok(Budget::query()->where('fiscal_year_id', $year->id)
            ->when($request->integer('branch_id'), fn ($q, $v) => $q->where('branch_id', $v), fn ($q) => $q->whereNull('branch_id'))
            ->with('account:id,code,name,type')->orderBy('account_id')->orderBy('month')->get());
    }

    /** Save a whole grid: rows of account x 12 months. Empty amounts remove the cell. */
    public function save(Request $request, int $yearId): JsonResponse
    {
        $year = FiscalYear::query()->findOrFail($yearId);
        if ($year->isClosed()) {
            throw new BusinessException(__('Budgets of a closed year cannot change.'));
        }
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'rows' => ['required', 'array', 'max:300'],
            'rows.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_header', false)->whereNull('deleted_at')],
            'rows.*.months' => ['required', 'array', 'size:12'],
            'rows.*.months.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        DB::transaction(function () use ($data, $year) {
            foreach ($data['rows'] as $row) {
                foreach (array_values($row['months']) as $i => $amount) {
                    $key = ['fiscal_year_id' => $year->id, 'account_id' => $row['account_id'], 'branch_id' => $data['branch_id'] ?? null, 'month' => $i + 1];
                    if ($amount === null || (float) $amount == 0) {
                        Budget::query()->where($key)->get()->each->delete();

                        continue;
                    }
                    Budget::query()->updateOrCreate($key, ['amount' => round((float) $amount, 2)]);
                }
            }
        });

        return $this->ok(null, __('Budget saved successfully.'));
    }
}
