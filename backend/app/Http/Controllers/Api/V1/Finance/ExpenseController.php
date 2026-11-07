<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Services\Finance\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->applyTrashed($this->branch()->scope(Expense::query()), $request)
            ->with(['category:id,name', 'branch:id,name', 'creator'])
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('title', 'like', "%{$t}%")->orWhere('description', 'like', "%{$t}%")->orWhere('number', 'like', "%{$t}%")))
            ->when($request->query('expense_category_id'), fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->where('expense_date', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('expense_date', '<=', $v.' 23:59:59'));
        $this->sortable($query, $request, ['expense_date', 'amount', 'title'], 'expense_date');

        $total = (float) (clone $query)->reorder()->sum('amount');

        return $this->paginated($query->paginate($this->perPage($request)), ['summary' => ['total' => round($total, 2)]]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok($this->branch()->scope(Expense::withTrashed())->with(['category', 'branch', 'creator', 'updater'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $expense = $this->expenses->create($data, $this->branch()->requireId());

        return $this->created($expense->load('category:id,name'), __('Expense recorded successfully.'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $expense = $this->branch()->scope(Expense::query())->findOrFail($id);
        $expense = $this->expenses->update($expense, $this->validated($request));

        return $this->ok($expense->fresh('category:id,name'), __('Expense updated successfully.'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $expense = $this->branch()->scope(Expense::query())->findOrFail($id);
        $this->expenses->delete($expense, $request->input('reason'));

        return $this->ok(null, __('Expense deleted. It can be restored.'));
    }

    public function restore(int $id): JsonResponse
    {
        $expense = $this->branch()->scope(Expense::onlyTrashed())->findOrFail($id);
        $this->expenses->restore($expense);

        return $this->ok($expense, __('Expense restored successfully.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'expense_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'payment_method' => ['required', Rule::in(['cash', 'mobile_money', 'bank', 'cheque', 'card', 'lipa_namba'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
        ]);
    }
}
