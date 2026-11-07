<?php

namespace App\Services\Finance;

use App\Models\Finance\Expense;
use App\Services\Core\NumberGenerator;
use Illuminate\Support\Facades\DB;

/** Expenses with their accounting entries kept in step on every change. */
class ExpenseService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly PostingService $posting,
        private readonly LedgerService $ledger,
    ) {}

    public function create(array $data, int $branchId): Expense
    {
        $this->ledger->assertOpen($data['expense_date']);

        return DB::transaction(function () use ($data, $branchId) {
            $expense = Expense::create($data + ['number' => $this->numbers->next('expense'), 'branch_id' => $branchId]);
            $this->posting->expense($expense);

            return $expense;
        });
    }

    public function update(Expense $expense, array $data): Expense
    {
        $this->ledger->assertOpen($expense->expense_date);
        $this->ledger->assertOpen($data['expense_date'] ?? $expense->expense_date);

        return DB::transaction(function () use ($expense, $data) {
            $expense->fill($data);
            $accountingChanged = $expense->isDirty(['amount', 'expense_category_id', 'payment_method', 'expense_date', 'branch_id']);
            $expense->save();
            if ($accountingChanged) {
                $this->posting->expenseChanged($expense, __('Expense :number edited', ['number' => $expense->number]));
            }

            return $expense;
        });
    }

    public function delete(Expense $expense, ?string $reason): void
    {
        $this->ledger->assertOpen($expense->expense_date);

        DB::transaction(function () use ($expense, $reason) {
            $expense->auditReason = $reason;
            $expense->delete();
            $this->posting->reverse($expense, __('Expense :number deleted', ['number' => $expense->number]).($reason ? ": {$reason}" : ''));
        });
    }

    public function restore(Expense $expense): void
    {
        $this->ledger->assertOpen($expense->expense_date);

        DB::transaction(function () use ($expense) {
            $expense->restore();
            $this->posting->expense($expense);
        });
    }
}
