<?php

namespace App\Support\Finance;

/**
 * Default chart of accounts. System accounts (with a key) are used by the
 * automatic postings and are recreated on demand if they were removed.
 *
 * Columns: code, name, type, sub_type, parent code, system key, header?
 */
final class ChartOfAccounts
{
    public static function defaults(): array
    {
        return [
            ['1000', 'Assets', 'asset', null, null, null, true],
            ['1100', 'Cash on hand', 'asset', 'cash', '1000', 'cash', false],
            ['1110', 'Petty cash', 'asset', 'cash', '1000', null, false],
            ['1200', 'Bank accounts', 'asset', 'bank', '1000', 'bank', false],
            ['1250', 'Mobile money wallets', 'asset', 'mobile_money', '1000', 'mobile_money', false],
            ['1300', 'Accounts receivable - customers', 'asset', 'receivable', '1000', 'receivable', false],
            ['1310', 'Staff advances and loans', 'asset', 'receivable', '1000', 'employee_advances', false],
            ['1320', 'Seller shortages receivable', 'asset', 'receivable', '1000', 'seller_receivable', false],
            ['1400', 'Inventory', 'asset', 'inventory', '1000', 'inventory', false],
            ['1410', 'Goods in transit between branches', 'asset', 'inventory', '1000', 'goods_in_transit', false],
            ['1500', 'VAT input (recoverable)', 'asset', 'tax', '1000', 'vat_input', false],
            ['1600', 'Prepayments', 'asset', 'other_current_asset', '1000', null, false],
            ['1700', 'Fixed assets', 'asset', 'fixed_asset', '1000', null, true],
            ['1710', 'Furniture and equipment', 'asset', 'fixed_asset', '1700', null, false],
            ['1720', 'Motor vehicles', 'asset', 'fixed_asset', '1700', null, false],
            ['1730', 'Buildings', 'asset', 'fixed_asset', '1700', null, false],
            ['1790', 'Accumulated depreciation', 'asset', 'fixed_asset', '1700', null, false],

            ['2000', 'Liabilities', 'liability', null, null, null, true],
            ['2100', 'Accounts payable - suppliers', 'liability', 'payable', '2000', 'payable', false],
            ['2200', 'Customer savings and deposits', 'liability', 'customer_deposit', '2000', 'customer_savings', false],
            ['2300', 'VAT payable', 'liability', 'tax', '2000', 'vat_payable', false],
            ['2400', 'Salaries payable', 'liability', 'payroll', '2000', 'salaries_payable', false],
            ['2410', 'PAYE payable', 'liability', 'tax', '2000', 'paye_payable', false],
            ['2420', 'NSSF payable', 'liability', 'payroll', '2000', 'nssf_payable', false],
            ['2430', 'SDL payable', 'liability', 'tax', '2000', 'sdl_payable', false],
            ['2440', 'WCF payable', 'liability', 'payroll', '2000', 'wcf_payable', false],
            ['2450', 'Health insurance (NHIF) payable', 'liability', 'payroll', '2000', 'nhif_payable', false],
            ['2460', 'Other payroll deductions payable', 'liability', 'payroll', '2000', 'other_deductions_payable', false],
            ['2500', 'Loans payable', 'liability', 'long_term_liability', '2000', null, false],

            ['3000', 'Equity', 'equity', null, null, null, true],
            ['3100', "Owner's capital", 'equity', 'equity', '3000', 'capital', false],
            ['3200', 'Retained earnings', 'equity', 'equity', '3000', 'retained_earnings', false],
            ['3300', 'Opening balance equity', 'equity', 'equity', '3000', 'opening_equity', false],
            ['3400', "Owner's drawings", 'equity', 'equity', '3000', 'drawings', false],

            ['4000', 'Income', 'income', null, null, null, true],
            ['4100', 'Sales revenue', 'income', 'revenue', '4000', 'sales', false],
            ['4110', 'Sales returns', 'income', 'revenue', '4000', 'sales_returns', false],
            ['4200', 'Delivery income', 'income', 'revenue', '4000', 'delivery_income', false],
            ['4300', 'Other income', 'income', 'other_income', '4000', 'other_income', false],
            ['4310', 'Inventory gains', 'income', 'other_income', '4000', 'inventory_gain', false],

            ['5000', 'Cost of sales', 'expense', null, null, null, true],
            ['5100', 'Cost of goods sold', 'expense', 'cost_of_sales', '5000', 'cogs', false],
            ['5200', 'Inventory losses (damage, expiry, shortages)', 'expense', 'cost_of_sales', '5000', 'inventory_loss', false],

            ['6000', 'Operating expenses', 'expense', null, null, null, true],
            ['6100', 'Salaries and wages', 'expense', 'payroll', '6000', 'salaries_expense', false],
            ['6110', 'Employer statutory contributions', 'expense', 'payroll', '6000', 'payroll_tax_expense', false],
            ['6200', 'Rent', 'expense', 'operating', '6000', null, false],
            ['6210', 'Electricity', 'expense', 'operating', '6000', null, false],
            ['6220', 'Water', 'expense', 'operating', '6000', null, false],
            ['6300', 'Transport', 'expense', 'operating', '6000', null, false],
            ['6310', 'Fuel', 'expense', 'operating', '6000', null, false],
            ['6400', 'Office supplies', 'expense', 'operating', '6000', null, false],
            ['6500', 'Repairs and maintenance', 'expense', 'operating', '6000', null, false],
            ['6600', 'Taxes and licences', 'expense', 'operating', '6000', null, false],
            ['6700', 'Marketing', 'expense', 'operating', '6000', null, false],
            ['6800', 'Bank and mobile money charges', 'expense', 'operating', '6000', 'bank_charges', false],
            ['6850', 'Depreciation', 'expense', 'operating', '6000', null, false],
            ['6900', 'Other expenses', 'expense', 'operating', '6000', 'general_expense', false],
        ];
    }

    /** Default expense account per expense category name (seeded categories). */
    public static function expenseCategoryAccounts(): array
    {
        return [
            'Rent' => '6200', 'Electricity' => '6210', 'Water' => '6220', 'Salaries & wages' => '6100',
            'Transport' => '6300', 'Fuel' => '6310', 'Office supplies' => '6400', 'Repairs & maintenance' => '6500',
            'Taxes & licences' => '6600', 'Marketing' => '6700', 'Bank charges' => '6800', 'Other' => '6900',
        ];
    }

    /** Which money account receives each payment method (system keys). */
    public static function paymentMethodAccounts(): array
    {
        return [
            'cash' => 'cash',
            'mobile_money' => 'mobile_money',
            'lipa_namba' => 'mobile_money',
            'online' => 'mobile_money',
            'bank' => 'bank',
            'card' => 'bank',
            'cheque' => 'bank',
            'savings' => 'customer_savings',
            'credit_note' => 'receivable',
        ];
    }

    public static function bySystemKey(string $key): ?array
    {
        foreach (self::defaults() as $row) {
            if ($row[5] === $key) {
                return $row;
            }
        }

        return null;
    }
}
