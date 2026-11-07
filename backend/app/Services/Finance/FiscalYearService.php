<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessException;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\JournalEntry;
use App\Models\Finance\JournalLine;
use App\Services\Core\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Financial years. Closing a year moves its profit or loss into retained
 * earnings and locks every date in it; reopening undoes exactly that.
 */
class FiscalYearService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data): FiscalYear
    {
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();
        if ($end->lte($start) || $start->diffInMonths($end) > 18) {
            throw new BusinessException(__('A financial year must end after it starts and last at most 18 months.'));
        }
        $overlap = FiscalYear::query()
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();
        if ($overlap) {
            throw new BusinessException(__('These dates overlap another financial year.'), 'OVERLAP');
        }

        return FiscalYear::create(['name' => $data['name'], 'start_date' => $start, 'end_date' => $end]);
    }

    public function close(FiscalYear $year): FiscalYear
    {
        if ($year->isClosed()) {
            throw new BusinessException(__(':year is already closed.', ['year' => $year->name]));
        }
        if ($year->end_date->isFuture()) {
            throw new BusinessException(__('A year can only be closed after its last day.'));
        }
        $earlierOpen = FiscalYear::query()->where('status', 'open')->whereDate('end_date', '<', $year->start_date->toDateString())->exists();
        if ($earlierOpen) {
            throw new BusinessException(__('Close the earlier financial years first.'));
        }

        return DB::transaction(function () use ($year) {
            $from = $year->start_date->toDateString();
            $to = $year->end_date->toDateString();

            $rows = JournalLine::query()
                ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->whereIn('accounts.type', ['income', 'expense'])
                ->whereDate('journal_lines.entry_date', '>=', $from)
                ->whereDate('journal_lines.entry_date', '<=', $to)
                ->groupBy('journal_lines.account_id', 'journal_lines.branch_id')
                ->selectRaw('journal_lines.account_id, journal_lines.branch_id, SUM(journal_lines.debit) d, SUM(journal_lines.credit) c')
                ->get();

            $lines = [];
            $profit = 0.0;
            foreach ($rows as $row) {
                $net = round((float) $row->d - (float) $row->c, 2);
                if (abs($net) < 0.005) {
                    continue;
                }
                // Zero the account: post the opposite side.
                $lines[] = ['account' => (int) $row->account_id, 'debit' => $net < 0 ? -$net : 0, 'credit' => $net > 0 ? $net : 0, 'branch_id' => $row->branch_id];
                $profit -= $net;
            }
            if ($lines !== []) {
                $profit = round($profit, 2);
                $lines[] = ['account' => 'retained_earnings', 'debit' => $profit < 0 ? -$profit : 0, 'credit' => $profit > 0 ? $profit : 0];
            }

            $entry = $lines === [] ? null : $this->ledger->post([
                'date' => $to,
                'description' => __('Year-end closing :year: :result transferred to retained earnings', [
                    'year' => $year->name, 'result' => $profit >= 0 ? __('profit') : __('loss'),
                ]),
                'event' => 'year_end_close',
                'source' => $year,
            ], $lines);

            $year->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => Auth::id(),
                'closing_entry_id' => $entry?->id,
            ])->save();
            $this->audit->event('fiscal_year.closed', __(':year closed with net :result', ['year' => $year->name, 'result' => number_format($profit, 2)]), $year, ['profit' => $profit]);

            return $year->setAttribute('net_profit', $profit);
        });
    }

    public function reopen(FiscalYear $year, string $reason): FiscalYear
    {
        if (! $year->isClosed()) {
            throw new BusinessException(__(':year is not closed.', ['year' => $year->name]));
        }
        if (FiscalYear::query()->where('status', 'closed')->whereDate('start_date', '>', $year->end_date->toDateString())->exists()) {
            throw new BusinessException(__('Reopen the later financial years first.'));
        }

        return DB::transaction(function () use ($year, $reason) {
            $year->forceFill(['status' => 'open', 'closed_at' => null, 'closed_by' => null])->save();
            if ($year->closing_entry_id && ($entry = JournalEntry::query()->find($year->closing_entry_id))) {
                $this->ledger->reverse($entry, __('Year reopened: :reason', ['reason' => $reason]), $year->end_date);
            }
            $year->forceFill(['closing_entry_id' => null])->save();
            $this->audit->event('fiscal_year.reopened', __(':year reopened', ['year' => $year->name]), $year, ['reason' => $reason]);

            return $year;
        });
    }

    /** The year containing a date, created on demand (calendar year) so postings always have a home. */
    public function forDate(mixed $date): FiscalYear
    {
        $day = Carbon::parse($date);

        return FiscalYear::query()->whereDate('start_date', '<=', $day->toDateString())->whereDate('end_date', '>=', $day->toDateString())->first()
            ?? $this->create(['name' => 'FY '.$day->year, 'start_date' => $day->copy()->startOfYear(), 'end_date' => $day->copy()->endOfYear()]);
    }

    /** Retained earnings that would be booked if the year closed today (for the balance sheet). */
    public function unclosedProfit(?string $asOf, ?int $branchId = null): float
    {
        $row = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('accounts.type', ['income', 'expense'])
            ->when($asOf, fn ($q) => $q->whereDate('journal_lines.entry_date', '<=', $asOf))
            ->when($branchId, fn ($q) => $q->where('journal_lines.branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(journal_lines.credit),0) - COALESCE(SUM(journal_lines.debit),0) p')
            ->first();

        return round((float) $row->p, 2);
    }
}
