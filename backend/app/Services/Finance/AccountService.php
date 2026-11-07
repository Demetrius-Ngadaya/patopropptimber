<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessException;
use App\Models\Finance\Account;
use App\Models\Finance\JournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chart of accounts maintenance. System accounts (the ones automatic postings
 * rely on) can be renamed but never deleted, re-typed or deactivated.
 */
class AccountService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function create(array $data): Account
    {
        $this->assertParent($data['parent_id'] ?? null, $data['type']);

        return Account::create($data + ['allow_manual' => true, 'is_active' => true]);
    }

    public function update(Account $account, array $data): Account
    {
        if ($account->system_key) {
            foreach (['type', 'is_header'] as $locked) {
                if (array_key_exists($locked, $data) && $data[$locked] != $account->{$locked}) {
                    throw new BusinessException(__('System accounts cannot change their type.'), 'SYSTEM_ACCOUNT');
                }
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw new BusinessException(__('System accounts cannot be deactivated.'), 'SYSTEM_ACCOUNT');
            }
        }
        if (isset($data['type']) && $data['type'] !== $account->type && $this->hasPostings($account)) {
            throw new BusinessException(__('This account already has postings, so its type cannot change.'));
        }
        if (! empty($data['is_header']) && ! $account->is_header && $this->hasPostings($account)) {
            throw new BusinessException(__('This account already has postings and cannot become a heading.'));
        }
        if (array_key_exists('parent_id', $data)) {
            $this->assertParent($data['parent_id'], $data['type'] ?? $account->type, $account);
        }

        $account->update($data);

        return $account;
    }

    public function delete(Account $account): void
    {
        if ($account->system_key) {
            throw new BusinessException(__('System accounts cannot be deleted.'), 'SYSTEM_ACCOUNT');
        }
        if (abs($this->balance($account)) > 0.004) {
            throw new BusinessException(__('Only accounts with a zero balance can be deleted.'), 'BALANCE_NOT_ZERO');
        }
        if ($account->children()->exists()) {
            throw new BusinessException(__('Move or delete the sub-accounts first.'));
        }
        $account->delete();
    }

    /**
     * Accounts as a tree with balances (debit-normal accounts positive when in debit,
     * credit-normal positive when in credit). Headings show the sum of their children.
     */
    public function tree(?string $asOf = null, ?int $branchId = null, bool $withInactive = false): Collection
    {
        $accounts = Account::query()
            ->when(! $withInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('code')->get();

        $totals = JournalLine::query()
            ->when($asOf, fn ($q) => $q->whereDate('entry_date', '<=', $asOf))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) d, SUM(credit) c')
            ->get()->keyBy('account_id');

        $balances = [];
        foreach ($accounts as $account) {
            $row = $totals->get($account->id);
            $net = $row ? (float) $row->d - (float) $row->c : 0.0;
            $balances[$account->id] = round($account->isDebitNormal() ? $net : -$net, 2);
        }

        // Roll balances up to headings (deepest first).
        $byParent = $accounts->groupBy('parent_id');
        $rollup = function (Account $account) use (&$rollup, $byParent, &$balances): float {
            $sum = $balances[$account->id] ?? 0.0;
            foreach ($byParent->get($account->id, collect()) as $child) {
                $sum += $rollup($child);
            }

            return $balances[$account->id] = round($sum, 2);
        };
        $accounts->whereNull('parent_id')->each(fn ($a) => $rollup($a));

        return $accounts->map(fn (Account $a) => $a->setAttribute('balance', $balances[$a->id] ?? 0.0));
    }

    public function balance(Account $account, ?string $asOf = null): float
    {
        $net = $this->ledger->movement($account->id, null, $asOf);

        return $account->isDebitNormal() ? $net : -$net;
    }

    private function hasPostings(Account $account): bool
    {
        return JournalLine::query()->where('account_id', $account->id)->exists();
    }

    private function assertParent(?int $parentId, string $type, ?Account $self = null): void
    {
        if (! $parentId) {
            return;
        }
        $parent = Account::query()->findOrFail($parentId);
        if ($parent->type !== $type) {
            throw new BusinessException(__('A sub-account must have the same type as its parent (:type).', ['type' => __($parent->type)]));
        }
        if (! $parent->is_header && $this->hasPostings($parent)) {
            throw new BusinessException(__('The parent account already has postings. Choose a heading account.'));
        }
        // No cycles.
        for ($node = $parent; $node && $self; $node = $node->parent) {
            if ($node->id === $self->id) {
                throw new BusinessException(__('An account cannot be placed under itself.'));
            }
        }
        if (! $parent->is_header) {
            DB::table('accounts')->where('id', $parent->id)->update(['is_header' => true]);
        }
    }
}
