<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\JournalEntry;
use App\Services\Finance\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Journal entries: browse everything, post manual and quick entries, reverse. */
class JournalController extends Controller
{
    public function __construct(private readonly JournalService $journals) {}

    public function index(Request $request): JsonResponse
    {
        $query = JournalEntry::query()
            ->with(['lines.account:id,code,name', 'branch:id,name', 'creator'])
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('description', 'like', "%{$t}%")->orWhere('reference', 'like', "%{$t}%")))
            ->when($request->query('event'), fn ($q, $v) => $q->where('event', $v))
            ->when($request->has('manual'), fn ($q) => $q->where('is_manual', $request->boolean('manual')))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('account_id'), fn ($q, $v) => $q->whereHas('lines', fn ($l) => $l->where('account_id', $v)))
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('entry_date', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('entry_date', '<=', $v))
            ->when(! $this->branch()->canAccessAll(), fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $this->branch()->accessibleIds())));
        $this->sortable($query, $request, ['entry_date', 'number', 'total'], 'entry_date');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok(JournalEntry::query()->with(['lines.account:id,code,name,type', 'lines.customer:id,name', 'lines.supplier:id,name', 'branch:id,name', 'creator', 'reversalOf:id,number', 'reversedBy:id,number', 'source'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
            'lines.*.customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'lines.*.supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
        ]);
        $entry = $this->journals->manual($data);

        return $this->created($entry, __('Journal :number posted successfully.', ['number' => $entry->number]));
    }

    public function quick(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(JournalService::QUICK_TYPES)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'account_id' => ['required_unless:type,transfer', 'nullable', 'integer'],
            'from_account_id' => ['required_if:type,transfer', 'nullable', 'integer'],
            'to_account_id' => ['required_if:type,transfer', 'nullable', 'integer', 'different:from_account_id'],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
        ]);
        $entry = $this->journals->quick($data);

        return $this->created($entry, __('Entry :number recorded successfully.', ['number' => $entry->number]));
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $reversal = $this->journals->reverse(JournalEntry::query()->findOrFail($id), $data['reason'], $request->user()->hasPermission('accounting.close_year'));

        return $this->created($reversal->load('lines.account:id,code,name'), __('Journal reversed by :number.', ['number' => $reversal->number]));
    }
}
