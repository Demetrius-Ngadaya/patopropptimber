<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessException;
use App\Models\Finance\Account;
use App\Models\Finance\JournalEntry;
use App\Services\Core\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Manual journals and the everyday "quick entries" an accountant needs:
 * moving money between accounts, owner capital and drawings, other income.
 */
class JournalService
{
    public const QUICK_TYPES = ['transfer', 'capital', 'drawings', 'other_income', 'loan_received', 'loan_repayment'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{entry_date: string, description: string, reference?: ?string, branch_id?: ?int, lines: array<int, array{account_id: int, debit?: ?float, credit?: ?float, memo?: ?string, customer_id?: ?int, supplier_id?: ?int}>}  $data
     */
    public function manual(array $data): JournalEntry
    {
        $lines = collect($data['lines']);
        $accounts = Account::query()->whereIn('id', $lines->pluck('account_id'))->get()->keyBy('id');

        foreach ($lines as $i => $line) {
            $account = $accounts->get($line['account_id']);
            if (! $account) {
                throw new BusinessException(__('Line :n: unknown account.', ['n' => $i + 1]));
            }
            if (! $account->allow_manual) {
                throw new BusinessException(__(':account is maintained automatically (sales, purchases, savings) and cannot take manual entries.', ['account' => $account->auditLabel()]), 'MANUAL_NOT_ALLOWED');
            }
            if ((float) ($line['debit'] ?? 0) > 0 && (float) ($line['credit'] ?? 0) > 0) {
                throw new BusinessException(__('Line :n has both a debit and a credit. Use one per line.', ['n' => $i + 1]));
            }
        }
        if ($lines->count() < 2) {
            throw new BusinessException(__('A journal needs at least two lines.'));
        }

        return DB::transaction(function () use ($data, $lines, $accounts) {
            $entry = $this->ledger->post([
                'date' => $data['entry_date'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'event' => 'manual',
                'is_manual' => true,
            ], $lines->map(fn ($l) => [
                'account' => $accounts->get($l['account_id']),
                'debit' => (float) ($l['debit'] ?? 0),
                'credit' => (float) ($l['credit'] ?? 0),
                'memo' => $l['memo'] ?? null,
                'customer_id' => $l['customer_id'] ?? null,
                'supplier_id' => $l['supplier_id'] ?? null,
            ])->all());

            if (! $entry) {
                throw new BusinessException(__('Enter at least one amount.'));
            }
            $this->audit->event('journal.manual', __('Manual journal :number posted', ['number' => $entry->number]), $entry, ['total' => (float) $entry->total]);

            return $entry->load('lines.account:id,code,name');
        });
    }

    /**
     * @param  array{type: string, amount: float, from_account_id?: ?int, to_account_id?: ?int, account_id?: ?int, entry_date: string, description?: ?string, reference?: ?string, branch_id?: ?int}  $data
     */
    public function quick(array $data): JournalEntry
    {
        $amount = round((float) $data['amount'], 2);
        $money = fn (?int $id) => $this->moneyAccount($id);

        [$debit, $credit, $label] = match ($data['type']) {
            // e.g. cash deposited to the bank, or mobile money withdrawn to cash
            'transfer' => [$money($data['to_account_id'] ?? null), $money($data['from_account_id'] ?? null), __('Transfer between accounts')],
            'capital' => [$money($data['account_id'] ?? null), $this->ledger->account('capital'), __('Owner capital introduced')],
            'drawings' => [$this->ledger->account('drawings'), $money($data['account_id'] ?? null), __('Owner drawings')],
            'other_income' => [$money($data['account_id'] ?? null), $this->ledger->account('other_income'), __('Other income')],
            'loan_received' => [$money($data['account_id'] ?? null), $this->loanAccount(), __('Loan received')],
            'loan_repayment' => [$this->loanAccount(), $money($data['account_id'] ?? null), __('Loan repayment')],
            default => throw new BusinessException(__('Unknown entry type.')),
        };
        if ($debit->id === $credit->id) {
            throw new BusinessException(__('Choose two different accounts.'));
        }

        $entry = $this->ledger->post([
            'date' => $data['entry_date'],
            'description' => ($data['description'] ?? null) ?: $label,
            'reference' => $data['reference'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'event' => 'quick_'.$data['type'],
            'is_manual' => true,
        ], [
            ['account' => $debit, 'debit' => $amount],
            ['account' => $credit, 'credit' => $amount],
        ]);
        $this->audit->event('journal.quick', $label.': '.number_format($amount, 2), $entry, ['type' => $data['type']]);

        return $entry->load('lines.account:id,code,name');
    }

    public function reverse(JournalEntry $entry, string $reason, bool $allowAutomatic): JournalEntry
    {
        if (! $entry->is_manual && ! $allowAutomatic) {
            throw new BusinessException(__('This entry was posted automatically. Cancel or correct the original document (sale, purchase, expense...) instead.'), 'AUTOMATIC_ENTRY');
        }
        $reversal = $this->ledger->reverse($entry, $reason);
        $this->audit->event('journal.reversed', __('Journal :number reversed', ['number' => $entry->number]), $entry, ['reason' => $reason]);

        return $reversal;
    }

    private function moneyAccount(?int $id): Account
    {
        $account = $id ? Account::query()->find($id) : null;
        if (! $account || ! in_array($account->sub_type, Account::MONEY_SUB_TYPES, true) || $account->is_header || ! $account->is_active) {
            throw new BusinessException(__('Choose a cash, bank or mobile money account.'), 'NOT_MONEY_ACCOUNT');
        }

        return $account;
    }

    private function loanAccount(): Account
    {
        return Account::query()->where('code', '2500')->first()
            ?? Account::query()->where('sub_type', 'long_term_liability')->where('is_header', false)->firstOrFail();
    }
}
