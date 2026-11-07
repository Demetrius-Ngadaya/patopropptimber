<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Core\User;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\Payslip;
use App\Models\Hr\SalaryAdvance;
use App\Models\Hr\SalaryComponent;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Core\SettingsService;
use App\Services\Finance\PostingService;
use App\Services\Notifications\StaffNotices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payroll: draft -> approved (posted to the ledger, advances recovered)
 * -> paid (salaries paid out). Statutory deductions are remitted separately.
 */
class PayrollService
{
    /** Statutory liabilities that can be remitted, with their ledger accounts. */
    public const REMITTABLE = [
        'paye' => 'paye_payable',
        'nssf' => 'nssf_payable',
        'sdl' => 'sdl_payable',
        'wcf' => 'wcf_payable',
        'nhif' => 'nhif_payable',
        'other' => 'other_deductions_payable',
    ];

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly LeaveService $leave,
        private readonly NumberGenerator $numbers,
        private readonly SettingsService $settings,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
        private readonly StaffNotices $notices,
    ) {}

    public function prepare(string $period, ?int $branchId, ?string $notes = null): PayrollRun
    {
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        if ($start->isAfter(now()->addMonth()->startOfMonth())) {
            throw new BusinessException(__('Payroll can be prepared at most one month ahead.'));
        }
        $exists = PayrollRun::query()->where('period', $period)->where('status', '!=', PayrollRun::CANCELLED)
            ->where(fn ($q) => $branchId ? $q->where('branch_id', $branchId)->orWhereNull('branch_id') : $q)->exists();
        if ($exists) {
            throw new BusinessException(__('A payroll for :period already exists.', ['period' => $period]), 'DUPLICATE_PAYROLL');
        }

        return DB::transaction(function () use ($period, $start, $end, $branchId, $notes) {
            $run = new PayrollRun(['period' => $period, 'period_start' => $start, 'period_end' => $end, 'branch_id' => $branchId, 'notes' => $notes]);
            $run->number = $this->numbers->next('payroll');
            $run->status = PayrollRun::DRAFT;
            $run->save();

            $this->fill($run);
            $run = $run->fresh();
            $this->notices->payrollPrepared($run);

            return $run;
        });
    }

    /** Recalculate a draft (after salary, leave or component changes). */
    public function recalculate(PayrollRun $run): PayrollRun
    {
        $this->assertStatus($run, PayrollRun::DRAFT);

        return DB::transaction(function () use ($run) {
            $run->payslips()->delete();
            $this->fill($run);

            return $run->fresh();
        });
    }

    public function approve(PayrollRun $run, User $by): PayrollRun
    {
        $this->assertStatus($run, PayrollRun::DRAFT);
        if ((int) $run->created_by === $by->id && ! $by->isSuperAdmin()) {
            throw new BusinessException(__('Payroll must be approved by someone other than the person who prepared it.'), 'SELF_APPROVAL', 403);
        }

        return DB::transaction(function () use ($run, $by) {
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($run, PayrollRun::DRAFT);

            // Recover advances in the order they were paid.
            foreach ($run->payslips as $slip) {
                $remaining = (float) $slip->advance_recovery;
                SalaryAdvance::query()->where('employee_id', $slip->employee_id)->where('status', 'paid')->orderBy('paid_at')->lockForUpdate()->get()
                    ->each(function (SalaryAdvance $a) use (&$remaining) {
                        if ($remaining <= 0) {
                            return false;
                        }
                        $take = round(min($remaining, $a->outstanding), 2);
                        $a->recovered = round((float) $a->recovered + $take, 2);
                        if ($a->outstanding <= 0.004) {
                            $a->status = 'recovered';
                        }
                        $a->save();
                        $remaining = round($remaining - $take, 2);
                    });
            }

            $this->posting->post([
                'date' => $run->period_end,
                'description' => __('Payroll :period (:number)', ['period' => $run->period, 'number' => $run->number]),
                'event' => 'payroll',
                'branch_id' => $run->branch_id,
                'reference' => $run->number,
            ], [
                ['account' => 'salaries_expense', 'debit' => (float) $run->gross],
                ['account' => 'payroll_tax_expense', 'debit' => round((float) $run->nssf_employer + (float) $run->sdl + (float) $run->wcf + (float) $run->nhif_employer, 2)],
                ['account' => 'paye_payable', 'credit' => (float) $run->paye],
                ['account' => 'nssf_payable', 'credit' => round((float) $run->nssf_employee + (float) $run->nssf_employer, 2)],
                ['account' => 'sdl_payable', 'credit' => (float) $run->sdl],
                ['account' => 'wcf_payable', 'credit' => (float) $run->wcf],
                ['account' => 'nhif_payable', 'credit' => round((float) $run->nhif_employee + (float) $run->nhif_employer, 2)],
                ['account' => 'other_deductions_payable', 'credit' => (float) $run->other_deductions],
                ['account' => 'employee_advances', 'credit' => (float) $run->advance_recovery],
                ['account' => 'salaries_payable', 'credit' => (float) $run->net],
            ], $run);

            $run->forceFill(['status' => PayrollRun::APPROVED, 'approved_by' => $by->id, 'approved_at' => now()])->save();
            $this->audit->event('payroll.approved', __('Payroll :number approved: net :net', ['number' => $run->number, 'net' => number_format((float) $run->net, 2)]), $run);

            return $run;
        });
    }

    public function pay(PayrollRun $run, string $method, ?string $reference, ?string $date = null): PayrollRun
    {
        $this->assertStatus($run, PayrollRun::APPROVED);

        return DB::transaction(function () use ($run, $method, $reference, $date) {
            $this->posting->post([
                'date' => $date ?? now(),
                'description' => __('Salaries paid :period (:number)', ['period' => $run->period, 'number' => $run->number]),
                'event' => 'payroll_payment',
                'branch_id' => $run->branch_id,
                'reference' => $reference ?: $run->number,
            ], [
                ['account' => 'salaries_payable', 'debit' => (float) $run->net],
                ['account' => $this->posting->moneyAccount($method), 'credit' => (float) $run->net],
            ], $run);

            $run->forceFill(['status' => PayrollRun::PAID, 'paid_at' => $date ? Carbon::parse($date) : now(), 'payment_method' => $method])->save();
            $this->audit->event('payroll.paid', __('Payroll :number paid', ['number' => $run->number]), $run, ['method' => $method, 'reference' => $reference]);

            return $run;
        });
    }

    /** Drafts are cancelled outright; approved (unpaid) runs are reversed in the ledger and advances restored. */
    public function cancel(PayrollRun $run, string $reason): PayrollRun
    {
        if (! in_array($run->status, [PayrollRun::DRAFT, PayrollRun::APPROVED], true)) {
            throw new BusinessException(__('Paid or cancelled payrolls cannot be cancelled.'));
        }

        return DB::transaction(function () use ($run, $reason) {
            if ($run->status === PayrollRun::APPROVED) {
                $this->posting->reverse($run, $reason, ['payroll']);
                foreach ($run->payslips as $slip) {
                    $restore = (float) $slip->advance_recovery;
                    SalaryAdvance::query()->where('employee_id', $slip->employee_id)->whereIn('status', ['paid', 'recovered'])->where('recovered', '>', 0)
                        ->orderByDesc('paid_at')->lockForUpdate()->get()
                        ->each(function (SalaryAdvance $a) use (&$restore) {
                            if ($restore <= 0) {
                                return false;
                            }
                            $give = round(min($restore, (float) $a->recovered), 2);
                            $a->recovered = round((float) $a->recovered - $give, 2);
                            $a->status = 'paid';
                            $a->save();
                            $restore = round($restore - $give, 2);
                        });
                }
            }
            $run->forceFill(['status' => PayrollRun::CANCELLED, 'notes' => trim(($run->notes ? $run->notes."\n" : '').__('Cancelled: :reason', ['reason' => $reason]))])->save();
            $this->audit->event('payroll.cancelled', __('Payroll :number cancelled', ['number' => $run->number]), $run, ['reason' => $reason]);

            return $run;
        });
    }

    /** Pay PAYE / NSSF / SDL / WCF etc. to the authority. */
    public function remit(string $type, float $amount, string $method, ?string $reference, string $date): void
    {
        $account = self::REMITTABLE[$type] ?? throw new BusinessException(__('Unknown statutory deduction.'));
        $this->posting->post([
            'date' => $date,
            'description' => __('Remittance of :type', ['type' => strtoupper($type)]),
            'event' => 'payroll_remittance',
            'reference' => $reference,
        ], [
            ['account' => $account, 'debit' => round($amount, 2)],
            ['account' => $this->posting->moneyAccount($method), 'credit' => round($amount, 2)],
        ]);
        $this->audit->event('payroll.remitted', __('Remitted :amount :type', ['amount' => number_format($amount, 2), 'type' => strtoupper($type)]), null, ['reference' => $reference]);
    }

    // ---------------------------------------------------------------- advances

    public function requestAdvance(Employee $employee, float $amount, int $installments, ?string $reason): SalaryAdvance
    {
        if (! $employee->isEmployed()) {
            throw new BusinessException(__(':name is no longer employed.', ['name' => $employee->full_name]));
        }
        $max = round((float) $employee->basic_salary * $this->settings->int('hr_max_advance_percent') / 100, 2);
        $open = (float) SalaryAdvance::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved', 'paid'])
            ->get()->sum(fn ($a) => $a->outstanding);
        if ($amount + $open > $max + 0.004) {
            throw new BusinessException(__('The advance limit is :max (outstanding: :open).', ['max' => number_format($max, 2), 'open' => number_format($open, 2)]), 'ADVANCE_LIMIT');
        }

        $advance = new SalaryAdvance(['employee_id' => $employee->id, 'amount' => round($amount, 2), 'installments' => $installments, 'reason' => $reason]);
        $advance->number = $this->numbers->next('advance');
        $advance->monthly_deduction = round($amount / max(1, $installments), 2);
        $advance->status = 'pending';
        $advance->save();

        return $advance;
    }

    public function decideAdvance(SalaryAdvance $advance, bool $approve, User $by, ?string $note): SalaryAdvance
    {
        if ($advance->status !== 'pending') {
            throw new BusinessException(__('This request has already been decided.'));
        }
        if ($advance->employee->user_id === $by->id) {
            throw new BusinessException(__('You cannot approve your own advance.'), 'SELF_APPROVAL', 403);
        }
        $advance->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->event('advance.'.$advance->status, __('Advance :number :status', ['number' => $advance->number, 'status' => __($advance->status)]), $advance);

        return $advance;
    }

    public function payAdvance(SalaryAdvance $advance, string $method, ?string $reference): SalaryAdvance
    {
        if ($advance->status !== 'approved') {
            throw new BusinessException(__('Only approved advances can be paid.'));
        }

        return DB::transaction(function () use ($advance, $method, $reference) {
            $this->posting->post([
                'date' => now(),
                'description' => __('Salary advance :number to :name', ['number' => $advance->number, 'name' => $advance->employee->full_name]),
                'event' => 'salary_advance',
                'branch_id' => $advance->employee->branch_id,
                'reference' => $reference ?: $advance->number,
            ], [
                ['account' => 'employee_advances', 'debit' => (float) $advance->amount, 'employee_id' => $advance->employee_id],
                ['account' => $this->posting->moneyAccount($method), 'credit' => (float) $advance->amount],
            ], $advance);
            $advance->forceFill(['status' => 'paid', 'paid_at' => now(), 'payment_method' => $method, 'payment_reference' => $reference])->save();

            return $advance;
        });
    }

    // ----------------------------------------------------------------- helpers

    private function fill(PayrollRun $run): void
    {
        $start = $run->period_start->copy();
        $end = $run->period_end->copy();
        $employees = Employee::query()
            ->with(['position:id,title', 'department:id,name', 'components'])
            ->whereDate('hire_date', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereIn('status', Employee::ACTIVE_STATUSES)->orWhereDate('exit_date', '>=', $start->toDateString()))
            ->when($run->branch_id, fn ($q) => $q->where('branch_id', $run->branch_id))
            ->orderBy('employee_no')
            ->get();
        if ($employees->isEmpty()) {
            throw new BusinessException(__('There are no employees to pay for this period.'), 'NO_EMPLOYEES');
        }

        $sdlApplies = Employee::query()->employed()->count() >= $this->settings->int('hr_sdl_min_employees');
        $globals = SalaryComponent::query()->where('is_active', true)->where('applies_to_all', true)->get();
        $workingDays = max(1, $this->settings->int('hr_working_days_per_month'));
        $daysInMonth = $start->daysInMonth;
        $totals = array_fill_keys(PayrollRun::TOTALS, 0.0);

        foreach ($employees as $employee) {
            // Pro-rate by calendar days for joiners and leavers.
            $from = $employee->hire_date->max($start);
            $to = $employee->exit_date ? $employee->exit_date->min($end) : $end;
            $calendarDays = $from->diffInDays($to) + 1;
            $basic = round((float) $employee->basic_salary * min(1, $calendarDays / $daysInMonth), 2);

            $unpaidDays = $this->leave->unpaidDays($employee, $from, $to);
            $unpaid = round((float) $employee->basic_salary / $workingDays * $unpaidDays, 2);

            [$earnings, $deductions] = $this->components($employee, $globals, $basic, $end);
            $advanceDue = (float) SalaryAdvance::query()->where('employee_id', $employee->id)->where('status', 'paid')->get()
                ->sum(fn ($a) => min((float) $a->monthly_deduction, $a->outstanding));

            $r = $this->calculator->calculate([
                'basic' => $basic,
                'unpaid_deduction' => $unpaid,
                'earnings' => $earnings,
                'deductions' => $deductions,
                'advance_due' => $advanceDue,
                'paye_exempt' => $employee->paye_exempt,
                'nssf_exempt' => $employee->nssf_exempt,
                'sdl_applies' => $sdlApplies,
            ]);

            Payslip::create($r + [
                'payroll_run_id' => $run->id,
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'position' => $employee->position?->title,
                'department' => $employee->department?->name,
                'days_payable' => $calendarDays,
                'unpaid_days' => $unpaidDays,
                'payment_method' => $employee->payment_method,
                'bank_name' => $employee->payment_method === 'mobile_money' ? null : $employee->bank_name,
                'account_hint' => $this->hint($employee->payment_method === 'mobile_money' ? $employee->mobile_money_number : $employee->bank_account_number),
            ]);

            foreach (['gross', 'paye', 'nssf_employee', 'nssf_employer', 'sdl', 'wcf', 'nhif_employee', 'nhif_employer', 'other_deductions', 'advance_recovery', 'net'] as $k) {
                $totals[$k] = round($totals[$k] + (float) $r[$k], 2);
            }
        }
        $totals['employer_cost'] = round($totals['gross'] + $totals['nssf_employer'] + $totals['sdl'] + $totals['wcf'] + $totals['nhif_employer'], 2);

        $run->forceFill($totals + ['employees' => $employees->count()])->save();
    }

    /** @return array{0: array, 1: array} earnings and deductions for this employee */
    private function components(Employee $employee, $globals, float $basic, Carbon $periodEnd): array
    {
        $assigned = $employee->components->filter(fn ($c) => $c->is_active
            && (! $c->pivot->starts_on || $c->pivot->starts_on <= $periodEnd->toDateString())
            && (! $c->pivot->ends_on || $c->pivot->ends_on >= $periodEnd->copy()->startOfMonth()->toDateString()));
        $all = $assigned->keyBy('id');
        foreach ($globals as $g) {
            $all->put($g->id, $all->get($g->id, $g));
        }

        $earnings = $deductions = [];
        foreach ($all as $c) {
            $value = $c->pivot?->amount !== null && $c->pivot?->amount !== '' ? (float) $c->pivot->amount : (float) $c->amount;
            $amount = round($c->calculation === 'percent' ? $basic * $value / 100 : $value, 2);
            if ($c->type === 'earning') {
                $earnings[] = ['name' => $c->name, 'amount' => $amount, 'taxable' => (bool) $c->is_taxable, 'pensionable' => (bool) $c->is_pensionable];
            } else {
                $deductions[] = ['name' => $c->name, 'amount' => $amount];
            }
        }

        return [$earnings, $deductions];
    }

    private function hint(?string $number): ?string
    {
        return $number ? '••••'.substr(preg_replace('/\s+/', '', $number), -4) : null;
    }

    private function assertStatus(PayrollRun $run, string $status): void
    {
        if ($run->status !== $status) {
            throw new BusinessException(__('This payroll is :status.', ['status' => __($run->status)]), 'INVALID_STATUS');
        }
    }
}
