<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessException;
use App\Models\Finance\Account;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\JournalEntry;
use App\Models\Finance\JournalLine;
use App\Services\Core\NumberGenerator;
use App\Support\Finance\ChartOfAccounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Double-entry general ledger. The only class that writes journal entries.
 *
 *  - every entry must balance (debits = credits, to the cent)
 *  - posting into a closed fiscal year is refused
 *  - entries are never edited or deleted; reverse() posts the mirror entry
 */
class LedgerService
{
    /** @var array<string, Account> */
    private array $accountCache = [];

    public function __construct(private readonly NumberGenerator $numbers) {}

    /** Resolve a system account by key, recreating it from the default chart if it is missing. */
    public function account(string $key): Account
    {
        if (isset($this->accountCache[$key])) {
            return $this->accountCache[$key];
        }

        $account = Account::withTrashed()->where('system_key', $key)->first();
        if ($account?->trashed()) {
            $account->restore();
        }
        if (! $account) {
            $row = ChartOfAccounts::bySystemKey($key) ?? throw new BusinessException(__('Unknown system account :key.', ['key' => $key]));
            $parent = $row[4] ? Account::withTrashed()->where('code', $row[4])->first() : null;
            $code = $row[0];
            while (Account::withTrashed()->where('code', $code)->exists()) {
                $code .= '1';
            }
            $account = new Account(['code' => $code, 'name' => $row[1], 'type' => $row[2], 'sub_type' => $row[3], 'parent_id' => $parent?->id, 'is_header' => false]);
            $account->forceFill(['system_key' => $key])->save();
        }

        return $this->accountCache[$key] = $account;
    }

    /**
     * @param  array{date?: mixed, description: string, event?: ?string, source?: ?Model, branch_id?: ?int, reference?: ?string, is_manual?: bool}  $entry
     * @param  array<int, array{account: Account|string|int, debit?: float, credit?: float, memo?: ?string, customer_id?: ?int, supplier_id?: ?int, employee_id?: ?int, branch_id?: ?int}>  $lines
     */
    public function post(array $entry, array $lines): ?JournalEntry
    {
        $date = Carbon::parse($entry['date'] ?? now())->startOfDay();
        $normalized = [];
        $debits = $credits = 0;

        foreach ($lines as $line) {
            // Work in cents to avoid floating point drift.
            $amount = (int) round(((float) ($line['debit'] ?? 0) - (float) ($line['credit'] ?? 0)) * 100);
            if ($amount === 0) {
                continue;
            }
            $account = $this->resolve($line['account']);
            if ($account->is_header) {
                throw new BusinessException(__(':account is a heading account and cannot receive postings.', ['account' => $account->auditLabel()]));
            }
            if (! $account->is_active) {
                throw new BusinessException(__('The account :account is inactive.', ['account' => $account->auditLabel()]));
            }
            $normalized[] = [
                'account_id' => $account->id,
                'debit' => $amount > 0 ? $amount / 100 : 0,
                'credit' => $amount < 0 ? -$amount / 100 : 0,
                'memo' => isset($line['memo']) ? mb_substr((string) $line['memo'], 0, 255) : null,
                'customer_id' => $line['customer_id'] ?? null,
                'supplier_id' => $line['supplier_id'] ?? null,
                'employee_id' => $line['employee_id'] ?? null,
                'branch_id' => $line['branch_id'] ?? ($entry['branch_id'] ?? null),
            ];
            $amount > 0 ? $debits += $amount : $credits -= $amount;
        }

        if ($normalized === []) {
            return null; // nothing to record (e.g. a zero-value sale)
        }
        if ($debits !== $credits) {
            throw new BusinessException(__('The journal does not balance: debits :debits, credits :credits.', [
                'debits' => number_format($debits / 100, 2), 'credits' => number_format($credits / 100, 2),
            ]), 'UNBALANCED_JOURNAL');
        }
        $this->assertOpen($date);

        return DB::transaction(function () use ($entry, $normalized, $date, $debits) {
            $source = $entry['source'] ?? null;
            $journal = JournalEntry::create([
                'number' => $this->numbers->next('journal'),
                'entry_date' => $date->toDateString(),
                'branch_id' => $entry['branch_id'] ?? null,
                'description' => mb_substr($entry['description'], 0, 500),
                'reference' => $entry['reference'] ?? null,
                'event' => $entry['event'] ?? null,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'is_manual' => $entry['is_manual'] ?? false,
                'status' => JournalEntry::POSTED,
                'reversal_of_id' => $entry['reversal_of_id'] ?? null,
                'total' => $debits / 100,
                'created_by' => Auth::id(),
            ]);
            foreach ($normalized as $line) {
                JournalLine::create($line + ['journal_entry_id' => $journal->id, 'entry_date' => $date->toDateString()]);
            }

            return $journal;
        });
    }

    /** Post the mirror image of an entry and mark the original reversed. */
    public function reverse(JournalEntry $entry, string $reason, mixed $date = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $reason, $date) {
            $entry = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status === JournalEntry::REVERSED) {
                throw new BusinessException(__('Journal :number has already been reversed.', ['number' => $entry->number]));
            }
            if ($entry->reversal_of_id) {
                throw new BusinessException(__('Journal :number is itself a reversal and cannot be reversed.', ['number' => $entry->number]));
            }

            // Reverse on the original date when that period is still open, otherwise today.
            $when = $date ? Carbon::parse($date) : ($this->isOpen($entry->entry_date) ? $entry->entry_date : now());
            $reversal = $this->post([
                'date' => $when,
                'description' => __('Reversal of :number: :reason', ['number' => $entry->number, 'reason' => $reason]),
                'event' => $entry->event ? $entry->event.'_reversal' : 'reversal',
                'source' => $entry->source_type ? $entry->source : null,
                'branch_id' => $entry->branch_id,
                'reference' => $entry->number,
                'is_manual' => $entry->is_manual,
                'reversal_of_id' => $entry->id,
            ], $entry->lines->map(fn (JournalLine $l) => [
                'account' => (int) $l->account_id,
                'debit' => (float) $l->credit,
                'credit' => (float) $l->debit,
                'memo' => $l->memo,
                'customer_id' => $l->customer_id,
                'supplier_id' => $l->supplier_id,
                'employee_id' => $l->employee_id,
                'branch_id' => $l->branch_id,
            ])->all());

            $entry->forceFill(['status' => JournalEntry::REVERSED, 'reversed_by_id' => $reversal->id])->save();

            return $reversal;
        });
    }

    /** Reverse every live posting made for a business document (e.g. a cancelled sale). */
    public function reverseSource(Model $source, string $reason, ?array $events = null): void
    {
        JournalEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('status', JournalEntry::POSTED)
            ->whereNull('reversal_of_id')
            ->when($events, fn ($q) => $q->whereIn('event', $events))
            ->orderBy('id')
            ->get()
            ->each(fn (JournalEntry $e) => $this->reverse($e, $reason));
    }

    public function assertOpen(mixed $date): void
    {
        if (! $this->isOpen($date)) {
            throw new BusinessException(__('The financial year that contains :date is closed. Choose a date in an open year.', [
                'date' => Carbon::parse($date)->format('d/m/Y'),
            ]), 'PERIOD_CLOSED');
        }
    }

    public function isOpen(mixed $date): bool
    {
        $day = Carbon::parse($date)->toDateString();

        return ! FiscalYear::query()->where('status', 'closed')
            ->whereDate('start_date', '<=', $day)->whereDate('end_date', '>=', $day)->exists();
    }

    /**
     * Net movement (debit - credit) of an account.
     */
    public function movement(int $accountId, mixed $from = null, mixed $to = null, ?int $branchId = null): float
    {
        $row = JournalLine::query()
            ->where('account_id', $accountId)
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', Carbon::parse($from)->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', Carbon::parse($to)->toDateString()))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    private function resolve(Account|string|int $account): Account
    {
        if ($account instanceof Account) {
            return $account;
        }
        if (is_int($account)) {
            return Account::query()->findOrFail($account);
        }

        return $this->account($account);
    }
}
