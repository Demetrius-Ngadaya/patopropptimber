<?php

namespace App\Http\Controllers\Api\V1\Agency;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Agency\AgencyAccount;
use App\Models\Agency\AgencyCommission;
use App\Models\Agency\AgencyDay;
use App\Services\Agency\AgencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Uwakala: agency lines, morning opening / evening closing, commissions. */
class AgencyController extends Controller
{
    public function __construct(private readonly AgencyService $agency) {}

    // ------------------------------------------------------------------ accounts (lines)

    public function accounts(Request $request): JsonResponse
    {
        $query = AgencyAccount::query()->with('branch:id,name')->orderBy('sort_order')->orderBy('name');
        if ($id = $this->branch()->id()) {
            $query->forBranch($id);
        }
        if (! $request->boolean('all')) {
            $query->where('is_active', true);
        }

        return $this->ok([
            'accounts' => $query->get(),
            'providers' => collect(AgencyAccount::PROVIDERS)->map(fn ($p, $key) => ['key' => $key, 'name' => $p[0], 'kind' => $p[1]])->values(),
        ]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $account = AgencyAccount::create($this->accountData($request));

        return $this->created($account, __(':name added.', ['name' => $account->name]));
    }

    public function updateAccount(Request $request, int $id): JsonResponse
    {
        $account = AgencyAccount::findOrFail($id);
        $account->fill($this->accountData($request))->save();

        return $this->ok($account, __(':name saved.', ['name' => $account->name]));
    }

    public function destroyAccount(int $id): JsonResponse
    {
        $account = AgencyAccount::findOrFail($id);
        $account->delete();

        return $this->ok(null, __(':name removed. Its past figures are kept.', ['name' => $account->name]));
    }

    // ------------------------------------------------------------------ days

    public function today(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => ['nullable', 'date', 'before_or_equal:today']])['date'] ?? null;

        return $this->ok($this->agency->today($this->branch()->requireId(), $date));
    }

    public function days(Request $request): JsonResponse
    {
        $query = $this->branch()->scope(AgencyDay::query())->with(['balances', 'branch:id,name', 'opener:id,name', 'closer:id,name'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('business_date', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('business_date', '<=', $v))
            ->orderByDesc('business_date');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function showDay(int $id): JsonResponse
    {
        return $this->ok($this->findDay($id)->load(['balances.account', 'branch:id,name', 'opener:id,name', 'closer:id,name']));
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'cash' => ['required', 'numeric', 'min:0'],
            'balances' => ['present', 'array'],
            'balances.*.account_id' => ['required', 'integer', 'distinct'],
            'balances.*.amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $day = $this->agency->open($this->branch()->requireId(), $data, $request->user());

        return $this->created($day, __('Day opened. Total at opening: :total.', ['total' => number_format($day->opening_total, 2)]));
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cash' => ['required', 'numeric', 'min:0'],
            'balances' => ['present', 'array'],
            'balances.*.account_id' => ['required', 'integer', 'distinct'],
            'balances.*.amount' => ['required', 'numeric', 'min:0'],
            'capital_added' => ['nullable', 'numeric', 'min:0'],
            'capital_withdrawn' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $day = $this->agency->close($this->findDay($id), $data, $request->user());
        $diff = (float) $day->difference;

        return $this->ok($day, abs($diff) < 0.005
            ? __('Day closed. Total at closing: :total. Everything balances.', ['total' => number_format($day->closing_total, 2)])
            : __('Day closed. Total at closing: :total. :kind of :amount.', [
                'total' => number_format($day->closing_total, 2),
                'kind' => $diff < 0 ? __('Shortage') : __('Excess'),
                'amount' => number_format(abs($diff), 2),
            ]));
    }

    public function correct(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'opening_cash' => ['nullable', 'numeric', 'min:0'],
            'opening_balances' => ['nullable', 'array'],
            'opening_balances.*.account_id' => ['required', 'integer'],
            'opening_balances.*.amount' => ['required', 'numeric', 'min:0'],
            'closing_cash' => ['nullable', 'numeric', 'min:0'],
            'closing_balances' => ['nullable', 'array'],
            'closing_balances.*.account_id' => ['required', 'integer'],
            'closing_balances.*.amount' => ['required', 'numeric', 'min:0'],
            'capital_added' => ['nullable', 'numeric', 'min:0'],
            'capital_withdrawn' => ['nullable', 'numeric', 'min:0'],
        ]);
        $day = $this->agency->correct($this->findDay($id), $data, $data['reason']);

        return $this->ok($day, __('Corrected.'));
    }

    public function summary(Request $request): JsonResponse
    {
        $f = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date']]);
        $from = Carbon::parse($f['date_from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($f['date_to'] ?? today())->endOfDay();

        return $this->ok($this->agency->summary($this->branchIds(), $from, $to) + ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
    }

    // ------------------------------------------------------------------ commissions

    public function commissions(Request $request): JsonResponse
    {
        $query = $this->branch()->scope(AgencyCommission::query())->with(['account:id,name,provider', 'branch:id,name', 'creator'])
            ->when($request->query('period'), fn ($q, $v) => $q->where('period', $v))
            ->when($request->query('agency_account_id'), fn ($q, $v) => $q->where('agency_account_id', $v))
            ->orderByDesc('period')->orderByDesc('received_on');
        $total = (float) (clone $query)->sum('amount');

        return $this->paginated($query->paginate($this->perPage($request)), ['total' => round($total, 2)]);
    }

    public function storeCommission(Request $request): JsonResponse
    {
        $commission = $this->agency->recordCommission($this->branch()->requireId(), $this->commissionData($request));

        return $this->created($commission, __('Commission of :amount recorded for :name.', ['amount' => number_format((float) $commission->amount, 2), 'name' => $commission->account?->name]));
    }

    public function updateCommission(Request $request, int $id): JsonResponse
    {
        $commission = $this->branch()->scope(AgencyCommission::query())->findOrFail($id);
        $commission = $this->agency->recordCommission($commission->branch_id, $this->commissionData($request), $commission);

        return $this->ok($commission, __('Commission saved.'));
    }

    public function destroyCommission(int $id): JsonResponse
    {
        $this->branch()->scope(AgencyCommission::query())->findOrFail($id)->delete();

        return $this->ok(null, __('Commission deleted.'));
    }

    // ------------------------------------------------------------------ helpers

    private function findDay(int $id): AgencyDay
    {
        return $this->branch()->scope(AgencyDay::query())->findOrFail($id);
    }

    /** @return array<int>|null null = every branch */
    private function branchIds(): ?array
    {
        if ($id = $this->branch()->id()) {
            return [$id];
        }

        return $this->branch()->canAccessAll() ? null : $this->branch()->accessibleIds();
    }

    private function accountData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'provider' => ['required', Rule::in(array_keys(AgencyAccount::PROVIDERS))],
            'kind' => ['nullable', Rule::in(AgencyAccount::KINDS)],
            'agent_number' => ['nullable', 'string', 'max:60'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $data['kind'] ??= AgencyAccount::PROVIDERS[$data['provider']][1];
        $data['sort_order'] ??= 0;

        return $data;
    }

    private function commissionData(Request $request): array
    {
        $data = $request->validate([
            'agency_account_id' => ['required', 'integer', 'exists:agency_accounts,id'],
            'period' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['period'] > now()->format('Y-m')) {
            throw new BusinessException(__('The commission month cannot be in the future.'));
        }

        return $data;
    }
}
