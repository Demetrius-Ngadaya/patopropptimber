<?php

namespace App\Services\Agency;

use App\Exceptions\BusinessException;
use App\Models\Agency\AgencyAccount;
use App\Models\Agency\AgencyCommission;
use App\Models\Agency\AgencyDay;
use App\Models\Agency\AgencyDayBalance;
use App\Models\Core\User;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\StaffNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Uwakala day: in the morning the agent counts cash in hand and the float on
 * every line (M-Pesa, CRDB ...); at night the same again. Money only moves
 * between cash and floats during the day, so the total should stay the same
 * apart from capital put in or taken out - the rest is a shortage or excess.
 */
class AgencyService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    /** @return Collection<int, AgencyAccount> */
    public function accounts(int $branchId, bool $activeOnly = true): Collection
    {
        return AgencyAccount::query()->forBranch($branchId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * What the opening screen needs: the day (if already opened), the lines,
     * and last night's figures to start from.
     */
    public function today(int $branchId, ?string $date = null): array
    {
        $date = Carbon::parse($date ?? today())->toDateString();
        $day = AgencyDay::query()->with(['balances.account', 'opener:id,name', 'closer:id,name'])
            ->where('branch_id', $branchId)->whereDate('business_date', $date)->first();
        $previous = AgencyDay::query()->with('balances')->where('branch_id', $branchId)
            ->whereDate('business_date', '<', $date)->orderByDesc('business_date')->first();

        $suggestion = null;
        if ($previous && $previous->status === 'closed' && $this->settings->bool('agency_carry_forward')) {
            $suggestion = [
                'from_date' => $previous->business_date->toDateString(),
                'cash' => (float) $previous->closing_cash,
                'floats' => $previous->balances->mapWithKeys(fn ($b) => [$b->agency_account_id => (float) $b->closing_float])->all(),
            ];
        }

        return [
            'date' => $date,
            'day' => $day,
            'accounts' => $this->accounts($branchId),
            'suggestion' => $suggestion,
            // A day left open blocks the next one: the figures would not join up.
            'unclosed' => $previous && $previous->status === 'open' ? ['id' => $previous->id, 'date' => $previous->business_date->toDateString()] : null,
        ];
    }

    /** @param array{date?: ?string, cash: float, balances: array<int, array{account_id: int, amount: float}>, note?: ?string} $data */
    public function open(int $branchId, array $data, User $user): AgencyDay
    {
        $date = Carbon::parse($data['date'] ?? today())->startOfDay();
        if ($date->isFuture()) {
            throw new BusinessException(__('A day in the future cannot be opened.'));
        }

        return DB::transaction(function () use ($branchId, $data, $user, $date) {
            if (AgencyDay::query()->where('branch_id', $branchId)->whereDate('business_date', $date)->lockForUpdate()->exists()) {
                throw new BusinessException(__('This day is already open.'), 'ALREADY_OPEN');
            }
            $unclosed = AgencyDay::query()->where('branch_id', $branchId)->where('status', 'open')->whereDate('business_date', '<', $date)->first();
            if ($unclosed) {
                throw new BusinessException(__('Close :date first: a day that is still open would leave a gap in the figures.', ['date' => $unclosed->business_date->format('d/m/Y')]), 'PREVIOUS_OPEN');
            }

            $day = AgencyDay::create([
                'branch_id' => $branchId,
                'business_date' => $date->toDateString(),
                'status' => 'open',
                'opening_cash' => round((float) $data['cash'], 2),
                'opened_at' => now(),
                'opened_by' => $user->id,
                'opening_note' => $data['note'] ?? null,
            ]);
            foreach ($this->lines($branchId, $data['balances']) as $accountId => $amount) {
                $day->balances()->create(['agency_account_id' => $accountId, 'opening_float' => $amount]);
            }
            $day->load('balances.account');
            $this->audit->event('agency.opened', __('Uwakala opened for :date: total :total', ['date' => $date->format('d/m/Y'), 'total' => number_format($day->opening_total, 2)]), $day);

            return $day;
        });
    }

    /** @param array{cash: float, balances: array<int, array{account_id: int, amount: float}>, capital_added?: ?float, capital_withdrawn?: ?float, note?: ?string} $data */
    public function close(AgencyDay $day, array $data, User $user): AgencyDay
    {
        if ($day->status !== 'open') {
            throw new BusinessException(__('This day is already closed.'));
        }

        $day = DB::transaction(function () use ($day, $data, $user) {
            $this->applyClosing($day, $data);
            $day->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $user->id])->save();

            return $day->fresh(['balances.account', 'opener:id,name', 'closer:id,name']);
        });
        $this->audit->event('agency.closed', __('Uwakala closed for :date: total :total, difference :difference', [
            'date' => $day->business_date->format('d/m/Y'), 'total' => number_format($day->closing_total, 2), 'difference' => number_format($day->difference, 2),
        ]), $day);
        $this->warnIfOff($day);

        return $day;
    }

    /** A manager corrects a day (opening and / or closing figures); the reason is kept in the audit log. */
    public function correct(AgencyDay $day, array $data, string $reason): AgencyDay
    {
        $day = DB::transaction(function () use ($day, $data) {
            if (isset($data['opening_cash'])) {
                $day->opening_cash = round((float) $data['opening_cash'], 2);
            }
            foreach ($this->lines($day->branch_id, $data['opening_balances'] ?? []) as $accountId => $amount) {
                $day->balances()->updateOrCreate(['agency_account_id' => $accountId], ['opening_float' => $amount]);
            }
            $day->save();
            if ($day->status === 'closed' && isset($data['closing_cash'])) {
                $this->applyClosing($day, ['cash' => $data['closing_cash'], 'balances' => $data['closing_balances'] ?? []] + $data);
            }

            return $day->fresh(['balances.account', 'opener:id,name', 'closer:id,name']);
        });
        $day->auditReason = $reason;
        $this->audit->event('agency.corrected', __('Uwakala :date corrected: :reason', ['date' => $day->business_date->format('d/m/Y'), 'reason' => $reason]), $day);

        return $day;
    }

    public function recordCommission(int $branchId, array $data, ?AgencyCommission $commission = null): AgencyCommission
    {
        $account = AgencyAccount::query()->forBranch($branchId)->find($data['agency_account_id']);
        if (! $account) {
            throw new BusinessException(__('This account is not used at this branch.'));
        }
        $commission ??= new AgencyCommission(['branch_id' => $branchId]);
        $commission->fill([
            'agency_account_id' => $account->id,
            'period' => $data['period'],
            'amount' => round((float) $data['amount'], 2),
            'received_on' => $data['received_on'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])->save();

        return $commission->load('account');
    }

    /** Totals for a period: days, floats per line, shortages / excesses and commissions. */
    public function summary(?array $branchIds, Carbon $from, Carbon $to): array
    {
        $days = AgencyDay::query()->with('balances.account')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('business_date')->get();
        $closed = $days->where('status', 'closed');
        $commissions = AgencyCommission::query()->with('account:id,name')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?: [0]))
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])->get();

        $accounts = $days->flatMap->balances->groupBy('agency_account_id')->map(function ($rows) {
            $last = $rows->sortBy(fn ($b) => $b->agency_day_id)->last();

            return [
                'id' => $last->agency_account_id,
                'name' => $last->account?->name,
                'days' => $rows->count(),
                'average_opening' => round((float) $rows->avg('opening_float'), 2),
                'latest' => (float) ($last->closing_float ?? $last->opening_float),
            ];
        })->sortBy('name')->values();
        $latest = $days->last();

        return [
            'days' => $days->count(),
            'closed_days' => $closed->count(),
            'open_days' => $days->where('status', 'open')->count(),
            'shortage' => round((float) $closed->filter(fn ($d) => $d->difference < 0)->sum('difference'), 2),
            'excess' => round((float) $closed->filter(fn ($d) => $d->difference > 0)->sum('difference'), 2),
            'capital_added' => round((float) $days->sum('capital_added'), 2),
            'capital_withdrawn' => round((float) $days->sum('capital_withdrawn'), 2),
            'latest_total' => $latest ? ($latest->closing_total ?? $latest->opening_total) : 0.0,
            'commissions' => round((float) $commissions->sum('amount'), 2),
            'commissions_by_account' => $commissions->groupBy('agency_account_id')->map(fn ($rows) => ['name' => $rows->first()->account?->name, 'amount' => round((float) $rows->sum('amount'), 2)])->sortByDesc('amount')->values(),
            'accounts' => $accounts,
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function applyClosing(AgencyDay $day, array $data): void
    {
        $day->closing_cash = round((float) $data['cash'], 2);
        $day->capital_added = round((float) ($data['capital_added'] ?? $day->capital_added ?? 0), 2);
        $day->capital_withdrawn = round((float) ($data['capital_withdrawn'] ?? $day->capital_withdrawn ?? 0), 2);
        if (array_key_exists('note', $data)) {
            $day->closing_note = $data['note'];
        }
        $given = $this->lines($day->branch_id, $data['balances']);
        $day->loadMissing('balances');
        $missing = $day->balances->reject(fn (AgencyDayBalance $b) => array_key_exists($b->agency_account_id, $given));
        if ($missing->isNotEmpty() && $day->status === 'open') {
            $missing->load('account');
            throw new BusinessException(__('Enter the closing float for :accounts.', ['accounts' => $missing->map(fn ($b) => $b->account?->name)->implode(', ')]), 'FLOAT_MISSING');
        }
        foreach ($given as $accountId => $amount) {
            // A line added during the day starts from zero in the morning.
            AgencyDayBalance::query()->updateOrCreate(['agency_day_id' => $day->id, 'agency_account_id' => $accountId], ['closing_float' => $amount]);
        }
        $day->save();
        $day->unsetRelation('balances');
    }

    /** @return array<int, float> account id => amount, only accounts of this branch */
    private function lines(int $branchId, array $balances): array
    {
        $allowed = AgencyAccount::query()->forBranch($branchId)->pluck('id')->all();
        $out = [];
        foreach ($balances as $line) {
            $id = (int) ($line['account_id'] ?? 0);
            if (! in_array($id, $allowed, true)) {
                throw new BusinessException(__('This account is not used at this branch.'));
            }
            $out[$id] = round((float) ($line['amount'] ?? 0), 2);
        }

        return $out;
    }

    private function warnIfOff(AgencyDay $day): void
    {
        $limit = (float) $this->settings->get('agency_difference_alert');
        if (abs((float) $day->difference) <= max($limit, 0.004)) {
            return;
        }
        $this->notifier->notify(new StaffNotice(
            type: 'agency_difference',
            title: $day->difference < 0 ? 'Uwakala closed short on :date' : 'Uwakala closed over on :date',
            body: ':branch: difference :amount (opening :opening, closing :closing).',
            params: [
                'date' => $day->business_date->format('d/m/Y'), 'branch' => $day->branch?->name, 'amount' => number_format($day->difference, 2),
                'opening' => number_format($day->opening_total, 2), 'closing' => number_format($day->closing_total, 2),
            ],
            level: 'warning',
            url: '/admin/agency/days/'.$day->id,
            branchId: $day->branch_id,
            permission: 'agency.manage',
        ));
    }
}
