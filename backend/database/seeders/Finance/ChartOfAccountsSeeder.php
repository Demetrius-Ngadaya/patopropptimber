<?php

namespace Database\Seeders\Finance;

use App\Models\Finance\Account;
use App\Models\Finance\ExpenseCategory;
use App\Models\Finance\FiscalYear;
use App\Support\Finance\ChartOfAccounts;
use Illuminate\Database\Seeder;

/** Default chart of accounts, expense categories linked to accounts and the current financial year. */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ChartOfAccounts::defaults() as [$code, $name, $type, $subType, $parentCode, $key, $header]) {
            $account = Account::withTrashed()->firstOrNew(['code' => $code]);
            if ($account->exists) {
                continue; // never overwrite an accountant's edits
            }
            $account->fill([
                'name' => $name,
                'type' => $type,
                'sub_type' => $subType,
                'parent_id' => $parentCode ? Account::query()->where('code', $parentCode)->value('id') : null,
                'is_header' => $header,
                'allow_manual' => ! in_array($key, ['receivable', 'payable', 'inventory', 'customer_savings'], true),
                'is_active' => true,
            ]);
            $account->forceFill(['system_key' => $key])->save();
        }

        $expenseAccounts = ChartOfAccounts::expenseCategoryAccounts();
        foreach (['Rent', 'Electricity', 'Water', 'Salaries & wages', 'Transport', 'Fuel', 'Office supplies', 'Repairs & maintenance', 'Taxes & licences', 'Marketing', 'Bank charges', 'Other'] as $name) {
            $category = ExpenseCategory::withTrashed()->firstOrCreate(['name' => $name], ['is_active' => true]);
            if (! $category->account_id && isset($expenseAccounts[$name])) {
                $category->forceFill(['account_id' => Account::query()->where('code', $expenseAccounts[$name])->value('id')])->saveQuietly();
            }
        }

        $year = (int) now()->format('Y');
        FiscalYear::query()->firstOrCreate(['name' => (string) $year], [
            'start_date' => "{$year}-01-01",
            'end_date' => "{$year}-12-31",
        ]);
    }
}
