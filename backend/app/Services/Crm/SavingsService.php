<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessException;
use App\Models\Crm\Customer;
use App\Models\Crm\SavingsAccount;
use App\Models\Crm\SavingsTransaction;
use App\Models\Sales\Sale;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Finance\PostingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Customer savings ("akiba"): customers deposit money over time and later buy
 * with it. Every change is an immutable SavingsTransaction that records the
 * balance before and after, so the full history can always be replayed.
 */
class SavingsService
{
    private const EPSILON = 0.005;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly PostingService $posting,
    ) {}

    public function open(Customer $customer, ?int $branchId, ?string $notes = null): SavingsAccount
    {
        if (SavingsAccount::withTrashed()->where('customer_id', $customer->id)->exists()) {
            throw new BusinessException(__(':customer already has a savings account.', ['customer' => $customer->name]));
        }

        return SavingsAccount::create([
            'account_no' => $this->numbers->next('savings'),
            'customer_id' => $customer->id,
            'branch_id' => $branchId,
            'status' => SavingsAccount::STATUS_ACTIVE,
            'notes' => $notes,
        ]);
    }

    /** @param array{amount: float, method: string, provider?: ?string, reference?: ?string, notes?: ?string, transacted_at?: mixed} $data */
    public function deposit(SavingsAccount $account, array $data, ?int $branchId): SavingsTransaction
    {
        return DB::transaction(function () use ($account, $data, $branchId) {
            $tx = $this->record($account, SavingsTransaction::DEPOSIT, (float) $data['amount'], [
                'branch_id' => $branchId,
                'method' => $data['method'],
                'provider' => $data['provider'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'transacted_at' => $data['transacted_at'] ?? now(),
            ]);
            $this->posting->savingsDeposit($tx);
            $this->audit->event('savings.deposit', __('Deposit of :amount to savings :account', ['amount' => number_format((float) $tx->amount, 2), 'account' => $tx->account->account_no]), $tx->account);

            return $tx;
        });
    }

    /** @param array{amount: float, method: string, reference?: ?string, notes: string} $data */
    public function withdraw(SavingsAccount $account, array $data, ?int $branchId): SavingsTransaction
    {
        return DB::transaction(function () use ($account, $data, $branchId) {
            $tx = $this->record($account, SavingsTransaction::WITHDRAWAL, (float) $data['amount'], [
                'branch_id' => $branchId,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'],
                'transacted_at' => now(),
            ]);
            $this->posting->savingsWithdrawal($tx);
            $this->audit->event('savings.withdrawal', __('Withdrawal of :amount from savings :account', ['amount' => number_format((float) $tx->amount, 2), 'account' => $tx->account->account_no]), $tx->account, ['reason' => $data['notes']]);

            return $tx;
        });
    }

    /**
     * Correct a deposit entered by mistake. History is never edited: a REVERSAL
     * transaction takes the money back out and the accounting entry is undone.
     */
    public function reverseDeposit(SavingsTransaction $deposit, string $reason): SavingsTransaction
    {
        if ($deposit->type !== SavingsTransaction::DEPOSIT) {
            throw new BusinessException(__('Only deposits can be reversed.'));
        }

        return DB::transaction(function () use ($deposit, $reason) {
            $deposit = SavingsTransaction::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if (SavingsTransaction::query()->where('reversed_transaction_id', $deposit->id)->exists()) {
                throw new BusinessException(__('This deposit has already been reversed.'), 'ALREADY_REVERSED');
            }

            $tx = $this->record($deposit->account, SavingsTransaction::REVERSAL, (float) $deposit->amount, [
                'branch_id' => $deposit->branch_id,
                'method' => $deposit->method,
                'reference' => $deposit->number,
                'reversed_transaction_id' => $deposit->id,
                'notes' => $reason,
                'transacted_at' => now(),
            ]);
            // The deposit was counted in total_deposited; take it back out of that total instead.
            $account = $tx->account;
            $account->forceFill([
                'total_deposited' => round(max(0, (float) $account->total_deposited - (float) $deposit->amount), 2),
            ])->saveQuietly();

            $this->posting->savingsDepositReversed($tx);
            $this->audit->event('savings.deposit_reversed', __('Deposit :number reversed', ['number' => $deposit->number]), $account, ['reason' => $reason, 'amount' => (float) $deposit->amount]);

            return $tx;
        });
    }

    /** Freeze, reactivate or close an account. Closing needs a zero balance. */
    public function changeStatus(SavingsAccount $account, string $status, string $reason): SavingsAccount
    {
        if (! in_array($status, SavingsAccount::STATUSES, true)) {
            throw new BusinessException(__('Unknown account status.'));
        }

        return DB::transaction(function () use ($account, $status, $reason) {
            $account = SavingsAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($account->status === $status) {
                throw new BusinessException(__('The savings account is already :status.', ['status' => __($status)]));
            }
            if ($status === SavingsAccount::STATUS_CLOSED && (float) $account->balance > self::EPSILON) {
                throw new BusinessException(__('Pay out the remaining balance (:balance) before closing the account.', ['balance' => number_format((float) $account->balance, 2)]), 'BALANCE_NOT_ZERO');
            }

            $from = $account->status;
            $account->forceFill(['status' => $status])->save();
            $this->audit->event('savings.status', __('Savings :account changed from :from to :to', ['account' => $account->account_no, 'from' => __($from), 'to' => __($status)]), $account, ['reason' => $reason]);

            return $account;
        });
    }

    /** Pay (part of) a purchase from savings. Called inside the sale transaction. */
    public function spend(Customer $customer, float $amount, Sale $sale, float $purchaseAmount): SavingsTransaction
    {
        $account = $this->accountFor($customer);

        return $this->record($account, SavingsTransaction::PURCHASE, $amount, [
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'method' => 'savings',
            'purchase_amount' => round($purchaseAmount, 2),
            'notes' => __('Purchase :sale', ['sale' => $sale->number]),
            'transacted_at' => $sale->sold_at ?? now(),
        ]);
    }

    /** Settle debt on a credit sale from savings. */
    public function payDebt(Customer $customer, float $amount, Sale $sale, ?int $branchId): SavingsTransaction
    {
        return $this->record($this->accountFor($customer), SavingsTransaction::DEBT_PAYMENT, $amount, [
            'branch_id' => $branchId,
            'sale_id' => $sale->id,
            'method' => 'savings',
            'notes' => __('Debt payment for :sale', ['sale' => $sale->number]),
            'transacted_at' => now(),
        ]);
    }

    /** Money given back to savings (sale cancelled / goods returned). */
    public function refund(Customer $customer, float $amount, Sale $sale, string $note): SavingsTransaction
    {
        return $this->record($this->accountFor($customer, allowInactive: true), SavingsTransaction::REFUND, $amount, [
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'method' => 'savings',
            'notes' => $note,
            'transacted_at' => now(),
        ]);
    }

    public function accountFor(Customer $customer, bool $allowInactive = false): SavingsAccount
    {
        $account = SavingsAccount::query()->where('customer_id', $customer->id)->first();
        if (! $account) {
            throw new BusinessException(__(':customer has no savings account.', ['customer' => $customer->name]), 'NO_SAVINGS_ACCOUNT');
        }
        if (! $allowInactive && ! $account->isActive()) {
            throw new BusinessException(__('The savings account :account is :status.', ['account' => $account->account_no, 'status' => __($account->status)]), 'SAVINGS_INACTIVE');
        }

        return $account;
    }

    public function availableBalance(Customer $customer): float
    {
        return (float) (SavingsAccount::query()->where('customer_id', $customer->id)->where('status', SavingsAccount::STATUS_ACTIVE)->value('balance') ?? 0);
    }

    private function record(SavingsAccount $account, string $type, float $amount, array $attributes): SavingsTransaction
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new BusinessException(__('The amount must be greater than zero.'));
        }

        return DB::transaction(function () use ($account, $type, $amount, $attributes) {
            /** @var SavingsAccount $account */
            $account = SavingsAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (in_array($type, [SavingsTransaction::DEPOSIT, SavingsTransaction::WITHDRAWAL], true) && ! $account->isActive()) {
                throw new BusinessException(__('The savings account :account is :status.', ['account' => $account->account_no, 'status' => __($account->status)]), 'SAVINGS_INACTIVE');
            }

            $before = (float) $account->balance;
            $credit = in_array($type, SavingsTransaction::CREDITS, true);
            if (! $credit && $amount - $before > self::EPSILON) {
                throw new BusinessException(__('Not enough savings. Available: :available, needed: :needed.', [
                    'available' => number_format($before, 2), 'needed' => number_format($amount, 2),
                ]), 'INSUFFICIENT_SAVINGS');
            }
            $after = round($credit ? $before + $amount : $before - $amount, 2);

            $tx = SavingsTransaction::create(array_merge($attributes, [
                'number' => $this->numbers->next('savings_tx'),
                'savings_account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => round($before, 2),
                'balance_after' => $after,
                'created_by' => Auth::id(),
            ]));

            $totals = ['balance' => $after, 'last_transaction_at' => now()];
            match ($type) {
                SavingsTransaction::DEPOSIT => $totals['total_deposited'] = round((float) $account->total_deposited + $amount, 2),
                SavingsTransaction::PURCHASE, SavingsTransaction::DEBT_PAYMENT => $totals['total_spent'] = round((float) $account->total_spent + $amount, 2),
                SavingsTransaction::REFUND => $totals['total_spent'] = round(max(0, (float) $account->total_spent - $amount), 2),
                SavingsTransaction::WITHDRAWAL => $totals['total_withdrawn'] = round((float) $account->total_withdrawn + $amount, 2),
                default => null,
            };
            $account->forceFill($totals)->saveQuietly();

            return $tx->setRelation('account', $account);
        });
    }
}
