<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Account;
use App\Models\Finance\BankReconciliation;
use App\Services\Finance\BankReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankReconciliationController extends Controller
{
    public function __construct(private readonly BankReconciliationService $reconciliations) {}

    public function index(Request $request): JsonResponse
    {
        $query = BankReconciliation::query()->with(['account:id,code,name', 'creator'])
            ->when($request->query('account_id'), fn ($q, $v) => $q->where('account_id', $v))
            ->orderByDesc('statement_date');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'statement_date' => ['required', 'date', 'before_or_equal:today'],
            'statement_balance' => ['required', 'numeric', 'between:-9999999999,9999999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $rec = $this->reconciliations->start(Account::query()->findOrFail($data['account_id']), $data['statement_date'], (float) $data['statement_balance'], $data['notes'] ?? null);

        return $this->created($rec, __('Reconciliation started.'));
    }

    public function show(int $id): JsonResponse
    {
        $rec = BankReconciliation::query()->with('account:id,code,name')->findOrFail($id);
        if ($rec->status === 'draft') {
            $rec = $this->reconciliations->refresh($rec);
        }
        $lines = $rec->status === 'draft'
            ? $this->reconciliations->candidates($rec)->limit(2000)->get()
            : $rec->lines()->with('entry:id,number,description,reference')->orderBy('entry_date')->get();

        return $this->ok(['reconciliation' => $rec, 'lines' => $lines]);
    }

    public function mark(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'line_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'line_ids.*' => ['integer'],
            'cleared' => ['required', 'boolean'],
        ]);
        $rec = $this->reconciliations->mark(BankReconciliation::query()->findOrFail($id), $data['line_ids'], $data['cleared']);

        return $this->ok($rec, __('Difference remaining: :diff', ['diff' => number_format((float) $rec->difference, 2)]));
    }

    public function complete(int $id): JsonResponse
    {
        $rec = $this->reconciliations->complete(BankReconciliation::query()->findOrFail($id));

        return $this->ok($rec, __('Reconciliation completed successfully.'));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->reconciliations->discard(BankReconciliation::query()->findOrFail($id));

        return $this->ok(null, __('Draft reconciliation discarded.'));
    }
}
