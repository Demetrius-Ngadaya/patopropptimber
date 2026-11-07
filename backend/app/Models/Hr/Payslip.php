<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    public const MONEY = ['basic_salary', 'unpaid_deduction', 'gross', 'taxable_income', 'pensionable', 'paye', 'nssf_employee', 'nssf_employer', 'sdl', 'wcf', 'nhif_employee', 'nhif_employer', 'other_deductions', 'advance_recovery', 'net'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_fill_keys(self::MONEY, 'decimal:2') + [
            'earnings' => 'array',
            'deductions' => 'array',
            'days_payable' => 'decimal:1',
            'unpaid_days' => 'decimal:1',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id')->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
