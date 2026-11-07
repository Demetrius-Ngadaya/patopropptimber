<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessException;
use App\Models\Finance\Account;
use App\Models\Finance\Budget;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\JournalLine;
use App\Models\Purchasing\Purchase;
use App\Models\Sales\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Financial statements straight from the general ledger:
 * trial balance, general ledger, profit & loss, balance sheet, cash flow,
 * VAT return, budget vs actual and aged receivables / payables.
 */
class FinancialReportService
{
    /** Year-end closing entries hide a year's results; the P&L must look through them. */
    private const CLOSING_EVENTS = ['year_end_close', 'year_end_close_reversal'];

    /** Cash flow classification of automatic postings (anything else is operating). */
    private const INVESTING_EVENTS = ['asset_acquired', 'asset_disposed'];

    private const FINANCING_EVENTS = ['quick_capital', 'quick_drawings', 'quick_loan_received', 'quick_loan_repayment'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly FiscalYearService $years,
    ) {}

    // ------------------------------------------------------------- trial balance

    public function trialBalance(?string $from, string $to, ?int $branchId = null): array
    {
        $accounts = Account::query()->where('is_header', false)->orderBy('code')->get()->keyBy('id');
        $closing = $this->sums(null, $to, $branchId);
        $opening = $from ? $this->sums(null, Carbon::parse($from)->subDay()->toDateString(), $branchId) : collect();
        $period = $from ? $this->sums($from, $to, $branchId) : collect();

        $rows = [];
        $totals = ['opening_debit' => 0, 'opening_credit' => 0, 'period_debit' => 0, 'period_credit' => 0, 'debit' => 0, 'credit' => 0];
        foreach ($accounts as $id => $account) {
            $c = $closing->get($id);
            $p = $period->get($id);
            $o = $opening->get($id);
            if (! $c && ! $p) {
                continue;
            }
            $net = $c ? (float) $c->d - (float) $c->c : 0.0;
            $openNet = $o ? (float) $o->d - (float) $o->c : 0.0;
            $row = [
                'account_id' => $id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type,
                'opening_debit' => round(max(0, $openNet), 2), 'opening_credit' => round(max(0, -$openNet), 2),
                'period_debit' => round((float) ($p->d ?? 0), 2), 'period_credit' => round((float) ($p->c ?? 0), 2),
                'debit' => round(max(0, $net), 2), 'credit' => round(max(0, -$net), 2),
            ];
            if ($row['debit'] == 0 && $row['credit'] == 0 && $row['period_debit'] == 0 && $row['period_credit'] == 0) {
                continue;
            }
            foreach ($totals as $k => $v) {
                $totals[$k] = round($v + $row[$k], 2);
            }
            $rows[] = $row;
        }

        return [
            'from' => $from, 'to' => $to,
            'rows' => $rows,
            'totals' => $totals,
            'balanced' => abs($totals['debit'] - $totals['credit']) < 0.01,
        ];
    }

    // ------------------------------------------------------------ general ledger

    public function generalLedger(Account $account, string $from, string $to, ?int $branchId = null): array
    {
        $sign = $account->isDebitNormal() ? 1 : -1;
        $opening = $sign * $this->ledger->movement($account->id, null, Carbon::parse($from)->subDay(), $branchId);

        $lines = JournalLine::query()
            ->where('account_id', $account->id)
            ->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['entry:id,number,description,reference,event,is_manual,status,source_type,source_id', 'customer:id,name', 'supplier:id,name'])
            ->orderBy('entry_date')->orderBy('id')
            ->limit(5000)
            ->get();

        $running = $opening;
        $rows = $lines->map(function (JournalLine $l) use (&$running, $sign) {
            $running = round($running + $sign * ((float) $l->debit - (float) $l->credit), 2);

            return [
                'date' => $l->entry_date->toDateString(),
                'journal_id' => $l->journal_entry_id,
                'number' => $l->entry?->number,
                'description' => $l->memo ?: $l->entry?->description,
                'reference' => $l->entry?->reference,
                'event' => $l->entry?->event,
                'party' => $l->customer?->name ?? $l->supplier?->name,
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
                'balance' => $running,
            ];
        });

        return [
            'account' => $account->only(['id', 'code', 'name', 'type', 'sub_type']),
            'from' => $from, 'to' => $to,
            'opening_balance' => round($opening, 2),
            'total_debit' => round((float) $lines->sum('debit'), 2),
            'total_credit' => round((float) $lines->sum('credit'), 2),
            'closing_balance' => round($running, 2),
            'lines' => $rows->all(),
        ];
    }

    // ------------------------------------------------------------ profit & loss

    public function profitAndLoss(string $from, string $to, ?int $branchId = null, bool $compare = false): array
    {
        $result = $this->pnlFor($from, $to, $branchId);
        if ($compare) {
            $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
            $prevTo = Carbon::parse($from)->subDay();
            $prevFrom = $prevTo->copy()->subDays($days - 1);
            $result['previous'] = $this->pnlFor($prevFrom->toDateString(), $prevTo->toDateString(), $branchId);
        }

        return $result;
    }

    private function pnlFor(string $from, string $to, ?int $branchId): array
    {
        $sums = $this->sums($from, $to, $branchId, excludeEvents: self::CLOSING_EVENTS);
        $accounts = Account::query()->whereIn('type', ['income', 'expense'])->where('is_header', false)->orderBy('code')->get();

        $sections = ['revenue' => [], 'cost_of_sales' => [], 'other_income' => [], 'operating_expenses' => []];
        foreach ($accounts as $account) {
            $s = $sums->get($account->id);
            if (! $s) {
                continue;
            }
            $net = (float) $s->d - (float) $s->c;
            $amount = round($account->type === 'income' ? -$net : $net, 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $section = match (true) {
                $account->type === 'income' && $account->sub_type === 'other_income' => 'other_income',
                $account->type === 'income' => 'revenue',
                $account->sub_type === 'cost_of_sales' => 'cost_of_sales',
                default => 'operating_expenses',
            };
            $sections[$section][] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'amount' => $amount];
        }

        $total = fn (string $k) => round(array_sum(array_column($sections[$k], 'amount')), 2);
        $revenue = $total('revenue');
        $gross = round($revenue - $total('cost_of_sales'), 2);
        $net = round($gross + $total('other_income') - $total('operating_expenses'), 2);

        return [
            'from' => $from, 'to' => $to,
            'sections' => $sections,
            'totals' => [
                'revenue' => $revenue,
                'cost_of_sales' => $total('cost_of_sales'),
                'gross_profit' => $gross,
                'gross_margin_percent' => $revenue > 0 ? round($gross / $revenue * 100, 1) : null,
                'other_income' => $total('other_income'),
                'operating_expenses' => $total('operating_expenses'),
                'net_profit' => $net,
                'net_margin_percent' => $revenue > 0 ? round($net / $revenue * 100, 1) : null,
            ],
        ];
    }

    // ------------------------------------------------------------ balance sheet

    public function balanceSheet(string $asOf, ?int $branchId = null): array
    {
        $sums = $this->sums(null, $asOf, $branchId);
        $accounts = Account::query()->whereIn('type', ['asset', 'liability', 'equity'])->where('is_header', false)->orderBy('code')->get();

        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        foreach ($accounts as $account) {
            $s = $sums->get($account->id);
            if (! $s) {
                continue;
            }
            $net = (float) $s->d - (float) $s->c;
            $amount = round($account->type === 'asset' ? $net : -$net, 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $sections[$account->type][] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'sub_type' => $account->sub_type, 'amount' => $amount];
        }

        $currentEarnings = $this->years->unclosedProfit($asOf, $branchId);
        if (abs($currentEarnings) >= 0.005) {
            $sections['equity'][] = ['account_id' => null, 'code' => null, 'name' => __('Profit / loss not yet closed'), 'sub_type' => 'current_earnings', 'amount' => $currentEarnings];
        }

        $assets = round(array_sum(array_column($sections['asset'], 'amount')), 2);
        $liabilities = round(array_sum(array_column($sections['liability'], 'amount')), 2);
        $equity = round(array_sum(array_column($sections['equity'], 'amount')), 2);

        return [
            'as_of' => $asOf,
            'sections' => $sections,
            'totals' => [
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equity' => $equity,
                'liabilities_and_equity' => round($liabilities + $equity, 2),
            ],
            'balanced' => abs($assets - $liabilities - $equity) < 0.01,
        ];
    }

    // ---------------------------------------------------------------- cash flow

    /** Direct method: every movement on cash, bank and mobile money accounts, by activity. */
    public function cashFlow(string $from, string $to, ?int $branchId = null): array
    {
        $money = Account::query()->money()->where('is_header', false)->pluck('id');
        $opening = round((float) JournalLine::query()->whereIn('account_id', $money)
            ->whereDate('entry_date', '<', $from)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) n')->value('n'), 2);

        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $money)
            ->whereDate('journal_lines.entry_date', '>=', $from)->whereDate('journal_lines.entry_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('journal_lines.branch_id', $branchId))
            ->where(fn ($q) => $q->whereNull('journal_entries.event')->orWhere('journal_entries.event', '!=', 'quick_transfer'))
            ->groupBy('journal_entries.event')
            ->selectRaw('journal_entries.event, SUM(journal_lines.debit) inflow, SUM(journal_lines.credit) outflow')
            ->get();

        $activities = ['operating' => [], 'investing' => [], 'financing' => []];
        foreach ($rows as $row) {
            $base = preg_replace('/_reversal$/', '', (string) $row->event);
            $activity = in_array($base, self::INVESTING_EVENTS, true) ? 'investing' : (in_array($base, self::FINANCING_EVENTS, true) ? 'financing' : 'operating');
            $activities[$activity][] = [
                'event' => $row->event ?: 'manual',
                'inflow' => round((float) $row->inflow, 2),
                'outflow' => round((float) $row->outflow, 2),
                'net' => round((float) $row->inflow - (float) $row->outflow, 2),
            ];
        }
        $net = fn (string $a) => round(array_sum(array_column($activities[$a], 'net')), 2);
        $change = round($net('operating') + $net('investing') + $net('financing'), 2);

        return [
            'from' => $from, 'to' => $to,
            'opening_cash' => $opening,
            'activities' => $activities,
            'totals' => ['operating' => $net('operating'), 'investing' => $net('investing'), 'financing' => $net('financing')],
            'net_change' => $change,
            'closing_cash' => round($opening + $change, 2),
        ];
    }

    // -------------------------------------------------------------------- VAT

    public function vat(string $from, string $to, ?int $branchId = null): array
    {
        $output = -$this->ledger->movement($this->ledger->account('vat_payable')->id, $from, $to, $branchId);
        $input = $this->ledger->movement($this->ledger->account('vat_input')->id, $from, $to, $branchId);

        $sales = Sale::query()->where('status', Sale::STATUS_COMPLETED)
            ->whereDate('sold_at', '>=', $from)->whereDate('sold_at', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(total),0) t, COALESCE(SUM(tax),0) x')->first();

        return [
            'from' => $from, 'to' => $to,
            'taxable_sales' => round((float) $sales->t - (float) $sales->x, 2),
            'output_vat' => round($output, 2),
            'input_vat' => round($input, 2),
            'net_vat_payable' => round($output - $input, 2),
        ];
    }

    // -------------------------------------------------------- budget vs actual

    public function budgetVsActual(FiscalYear $year, ?int $branchId = null): array
    {
        $budgets = Budget::query()->where('fiscal_year_id', $year->id)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->with('account:id,code,name,type')->get()->groupBy('account_id');

        $from = $year->start_date->toDateString();
        $to = $year->end_date->toDateString();
        $actuals = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $budgets->keys())
            ->whereDate('journal_lines.entry_date', '>=', $from)->whereDate('journal_lines.entry_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('journal_entries.event')->orWhereNotIn('journal_entries.event', self::CLOSING_EVENTS))
            ->when($branchId, fn ($q) => $q->where('journal_lines.branch_id', $branchId))
            ->get(['journal_lines.account_id', 'journal_lines.entry_date', 'journal_lines.debit', 'journal_lines.credit'])
            ->groupBy(fn ($l) => $l->account_id.'|'.Carbon::parse($l->entry_date)->format('Y-m'));

        $rows = [];
        foreach ($budgets as $accountId => $items) {
            $account = $items->first()->account;
            $sign = in_array($account->type, ['asset', 'expense'], true) ? 1 : -1;
            $months = [];
            $budgetTotal = $actualTotal = 0.0;
            foreach ($items->sortBy('month') as $b) {
                $key = $accountId.'|'.$year->start_date->copy()->addMonths($b->month - 1)->format('Y-m');
                $actual = round($sign * (float) collect($actuals->get($key, []))->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2);
                $months[] = ['month' => $b->month, 'budget' => (float) $b->amount, 'actual' => $actual, 'variance' => round($actual - (float) $b->amount, 2)];
                $budgetTotal += (float) $b->amount;
                $actualTotal += $actual;
            }
            $rows[] = [
                'account_id' => (int) $accountId, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type,
                'budget' => round($budgetTotal, 2), 'actual' => round($actualTotal, 2), 'variance' => round($actualTotal - $budgetTotal, 2),
                'used_percent' => $budgetTotal > 0 ? round($actualTotal / $budgetTotal * 100, 1) : null,
                'months' => $months,
            ];
        }

        return ['fiscal_year' => $year->only(['id', 'name', 'start_date', 'end_date']), 'rows' => $rows];
    }

    // ------------------------------------------------------------- ageing

    public function agedReceivables(string $asOf, ?int $branchId = null): array
    {
        $sales = Sale::query()->where('status', Sale::STATUS_COMPLETED)->where('balance', '>', 0)
            ->whereDate('sold_at', '<=', $asOf)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('customer:id,name,phone')
            ->get(['id', 'customer_id', 'customer_name', 'customer_phone', 'sold_at', 'due_date', 'balance']);

        return $this->age($sales, $asOf, fn (Sale $s) => [$s->customer_id ?? 'walk-in', $s->customer?->name ?? $s->customer_name ?? __('Walk-in'), $s->customer?->phone ?? $s->customer_phone]);
    }

    public function agedPayables(string $asOf, ?int $branchId = null): array
    {
        $purchases = Purchase::query()->where('balance', '>', 0)
            ->whereDate('purchase_date', '<=', $asOf)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('supplier:id,name,phone')
            ->get(['id', 'supplier_id', 'purchase_date', 'due_date', 'balance']);

        return $this->age($purchases, $asOf, fn ($p) => [$p->supplier_id ?? 'none', $p->supplier?->name ?? __('No supplier'), $p->supplier?->phone]);
    }

    private function age(Collection $documents, string $asOf, callable $party): array
    {
        $day = Carbon::parse($asOf)->startOfDay();
        $buckets = ['current', '1_30', '31_60', '61_90', 'over_90'];
        $parties = [];
        $totals = array_fill_keys($buckets, 0.0) + ['total' => 0.0];

        foreach ($documents as $doc) {
            [$id, $name, $phone] = $party($doc);
            $due = $doc->due_date ? Carbon::parse($doc->due_date)->startOfDay() : Carbon::parse($doc->sold_at ?? $doc->purchase_date)->startOfDay();
            $late = $due->lt($day) ? (int) $due->diffInDays($day) : 0;
            $bucket = match (true) {
                $late === 0 => 'current',
                $late <= 30 => '1_30',
                $late <= 60 => '31_60',
                $late <= 90 => '61_90',
                default => 'over_90',
            };
            $amount = (float) $doc->balance;
            $parties[$id] ??= ['party_id' => is_numeric($id) ? (int) $id : null, 'name' => $name, 'phone' => $phone] + array_fill_keys($buckets, 0.0) + ['total' => 0.0];
            $parties[$id][$bucket] = round($parties[$id][$bucket] + $amount, 2);
            $parties[$id]['total'] = round($parties[$id]['total'] + $amount, 2);
            $totals[$bucket] = round($totals[$bucket] + $amount, 2);
            $totals['total'] = round($totals['total'] + $amount, 2);
        }

        return ['as_of' => $asOf, 'rows' => collect($parties)->sortByDesc('total')->values()->all(), 'totals' => $totals];
    }

    // --------------------------------------------------------------- helpers

    /** @return Collection<int, object{d: string, c: string}> keyed by account_id */
    private function sums(?string $from, ?string $to, ?int $branchId, array $excludeEvents = []): Collection
    {
        return JournalLine::query()
            ->when($excludeEvents, fn (Builder $q) => $q->whereHas('entry', fn ($e) => $e->where(fn ($w) => $w->whereNull('event')->orWhereNotIn('event', $excludeEvents))))
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) d, SUM(credit) c')
            ->get()->keyBy('account_id');
    }

    public function assertRange(string $from, string $to): void
    {
        if (Carbon::parse($from)->gt(Carbon::parse($to))) {
            throw new BusinessException(__('The start date must be before the end date.'));
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 3700) {
            throw new BusinessException(__('Choose a period of at most 10 years.'));
        }
    }
}
