<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessException;
use App\Models\Finance\Account;
use App\Models\Finance\BankReconciliation;
use App\Models\Finance\JournalLine;
use App\Services\Core\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Bank / mobile money reconciliation: tick the book entries that appear on
 * the statement until the cleared balance equals the statement balance.
 * Missing items (bank charges, interest) are entered as journals first.
 */
class BankReconciliationService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function start(Account $account, string $statementDate, float $statementBalance, ?string $notes): BankReconciliation
    {
        if (! in_array($account->sub_type, Account::MONEY_SUB_TYPES, true) || $account->is_header) {
            throw new BusinessException(__('Only cash, bank and mobile money accounts can be reconciled.'), 'NOT_MONEY_ACCOUNT');
        }
        if (BankReconciliation::query()->where('account_id', $account->id)->where('status', 'draft')->exists()) {
            throw new BusinessException(__('Finish the open reconciliation of this account first.'));
        }
        $last = BankReconciliation::query()->where('account_id', $account->id)->where('status', 'completed')->max('statement_date');
        if ($last && $statementDate <= substr((string) $last, 0, 10)) {
            throw new BusinessException(__('The statement date must be after the last reconciliation (:date).', ['date' => substr((string) $last, 0, 10)]));
        }

        $rec = BankReconciliation::create([
            'account_id' => $account->id,
            'statement_date' => $statementDate,
            'statement_balance' => round($statementBalance, 2),
            'notes' => $notes,
        ]);

        return $this->refresh($rec);
    }

    /** Book lines still open on this account up to the statement date (plus those ticked in this reconciliation). */
    public function candidates(BankReconciliation $rec): Builder
    {
        return JournalLine::query()
            ->where('account_id', $rec->account_id)
            ->whereDate('entry_date', '<=', $rec->statement_date->toDateString())
            ->where(fn ($q) => $q->whereNull('reconciliation_id')->orWhere('reconciliation_id', $rec->id))
            ->with('entry:id,number,description,reference,event')
            ->orderBy('entry_date')->orderBy('id');
    }

    /** @param array<int, int> $lineIds */
    public function mark(BankReconciliation $rec, array $lineIds, bool $cleared): BankReconciliation
    {
        $this->assertDraft($rec);

        DB::transaction(function () use ($rec, $lineIds, $cleared) {
            $query = JournalLine::query()->whereIn('id', $lineIds)->where('account_id', $rec->account_id)
                ->whereDate('entry_date', '<=', $rec->statement_date->toDateString());
            $cleared
                ? $query->whereNull('reconciliation_id')->update(['reconciliation_id' => $rec->id])
                : $query->where('reconciliation_id', $rec->id)->update(['reconciliation_id' => null]);
        });

        return $this->refresh($rec);
    }

    public function complete(BankReconciliation $rec): BankReconciliation
    {
        $this->assertDraft($rec);
        $rec = $this->refresh($rec);
        if (abs((float) $rec->difference) >= 0.005) {
            throw new BusinessException(__('The cleared balance differs from the statement by :diff. Tick the missing items or record bank charges first.', [
                'diff' => number_format((float) $rec->difference, 2),
            ]), 'NOT_RECONCILED');
        }
        $rec->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->audit->event('bank.reconciled', __(':account reconciled to :date', ['account' => $rec->account->name, 'date' => $rec->statement_date->toDateString()]), $rec);

        return $rec;
    }

    public function discard(BankReconciliation $rec): void
    {
        $this->assertDraft($rec);
        DB::transaction(function () use ($rec) {
            JournalLine::query()->where('reconciliation_id', $rec->id)->update(['reconciliation_id' => null]);
            $rec->delete();
        });
    }

    public function refresh(BankReconciliation $rec): BankReconciliation
    {
        $date = $rec->statement_date->toDateString();
        $book = $this->ledger->movement($rec->account_id, null, $date);
        $cleared = (float) JournalLine::query()
            ->where('account_id', $rec->account_id)
            ->whereNotNull('reconciliation_id')
            ->whereDate('entry_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) n')->value('n');

        $rec->forceFill([
            'book_balance' => round($book, 2),
            'cleared_balance' => round($cleared, 2),
            'difference' => round((float) $rec->statement_balance - $cleared, 2),
        ])->saveQuietly();

        return $rec;
    }

    private function assertDraft(BankReconciliation $rec): void
    {
        if ($rec->status !== 'draft') {
            throw new BusinessException(__('This reconciliation is already completed.'));
        }
    }
}
