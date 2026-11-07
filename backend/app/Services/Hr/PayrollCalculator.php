<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Services\Core\SettingsService;

/**
 * Pure payroll maths: given pay inputs, returns every figure on the payslip.
 * Rates come from settings (PAYE bands, NSSF, SDL, WCF, health insurance)
 * so a change in the law is a settings change, not a code change.
 */
class PayrollCalculator
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array{basic: float, earnings: array<int, array{name: string, amount: float, taxable: bool, pensionable: bool}>, deductions: array<int, array{name: string, amount: float}>, unpaid_deduction?: float, advance_due?: float, paye_exempt?: bool, nssf_exempt?: bool, sdl_applies?: bool}  $in
     * @return array<string, mixed>
     */
    public function calculate(array $in): array
    {
        $basic = round(max(0, (float) $in['basic']), 2);
        $unpaid = round(min($basic, max(0, (float) ($in['unpaid_deduction'] ?? 0))), 2);
        $earnings = array_values(array_filter($in['earnings'] ?? [], fn ($e) => (float) $e['amount'] > 0));

        $extra = $taxableExtra = $pensionableExtra = 0.0;
        foreach ($earnings as $e) {
            $extra += (float) $e['amount'];
            $taxableExtra += $e['taxable'] ? (float) $e['amount'] : 0;
            $pensionableExtra += $e['pensionable'] ? (float) $e['amount'] : 0;
        }

        $gross = round($basic - $unpaid + $extra, 2);
        $pensionable = round($basic - $unpaid + $pensionableExtra, 2);

        $nssfEmployee = empty($in['nssf_exempt']) ? $this->percent($pensionable, 'hr_nssf_employee_rate') : 0.0;
        $nssfEmployer = empty($in['nssf_exempt']) ? $this->percent($pensionable, 'hr_nssf_employer_rate') : 0.0;
        $taxable = round(max(0, $basic - $unpaid + $taxableExtra - $nssfEmployee), 2);
        $paye = empty($in['paye_exempt']) ? $this->paye($taxable) : 0.0;
        $nhifEmployee = $this->percent($basic, 'hr_nhif_employee_rate');
        $nhifEmployer = $this->percent($basic, 'hr_nhif_employer_rate');
        $sdl = ($in['sdl_applies'] ?? true) ? $this->percent($gross, 'hr_sdl_rate') : 0.0;
        $wcf = $this->percent($gross, 'hr_wcf_rate');

        // Voluntary deductions and advance recovery never push the net below zero.
        $available = round($gross - $paye - $nssfEmployee - $nhifEmployee, 2);
        $deductions = [];
        $other = 0.0;
        foreach ($in['deductions'] ?? [] as $d) {
            $amount = round(min((float) $d['amount'], max(0, $available - $other)), 2);
            if ($amount > 0) {
                $deductions[] = ['name' => $d['name'], 'amount' => $amount];
                $other += $amount;
            }
        }
        $advance = round(min((float) ($in['advance_due'] ?? 0), max(0, $available - $other)), 2);
        $net = round($available - $other - $advance, 2);

        return [
            'basic_salary' => $basic,
            'unpaid_deduction' => $unpaid,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'gross' => $gross,
            'pensionable' => $pensionable,
            'taxable_income' => $taxable,
            'paye' => $paye,
            'nssf_employee' => $nssfEmployee,
            'nssf_employer' => $nssfEmployer,
            'nhif_employee' => $nhifEmployee,
            'nhif_employer' => $nhifEmployer,
            'sdl' => $sdl,
            'wcf' => $wcf,
            'other_deductions' => round($other, 2),
            'advance_recovery' => $advance,
            'net' => $net,
        ];
    }

    /** Monthly PAYE: base tax of the band plus rate% of the income above the band start. */
    public function paye(float $taxable): float
    {
        $bands = $this->bands();
        $tax = 0.0;
        foreach ($bands as $band) {
            if ($taxable > $band['from'] && ($band['to'] === null || $taxable <= $band['to'])) {
                $tax = $band['base'] + ($taxable - $band['from']) * $band['rate'] / 100;
                break;
            }
        }

        return round(max(0, $tax), 2);
    }

    /** @return array<int, array{from: float, to: ?float, rate: float, base: float}> */
    public function bands(): array
    {
        $bands = collect((array) $this->settings->get('hr_paye_bands'))
            ->map(fn ($b) => [
                'from' => (float) ($b['from'] ?? 0),
                'to' => isset($b['to']) && $b['to'] !== '' ? (float) $b['to'] : null,
                'rate' => (float) ($b['rate'] ?? 0),
                'base' => (float) ($b['base'] ?? 0),
            ])
            ->sortBy('from')->values()->all();

        if ($bands === []) {
            throw new BusinessException(__('PAYE bands are not configured. Set them in Settings > Payroll.'), 'PAYE_NOT_CONFIGURED');
        }

        return $bands;
    }

    private function percent(float $amount, string $setting): float
    {
        return round($amount * (float) $this->settings->get($setting) / 100, 2);
    }
}
