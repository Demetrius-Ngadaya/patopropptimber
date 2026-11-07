<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Payslip') }} {{ $run->period }} - {{ $slip->employee_name }}</title>
    @php($money = fn ($v) => number_format((float) $v, 2))
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #111827; margin: 0; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 6px; margin-bottom: 10px; }
        .header h1 { font-size: 15px; margin: 0; color: #0f766e; }
        .meta { color: #4b5563; font-size: 9px; }
        .title { font-size: 13px; font-weight: bold; margin: 6px 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 2px 4px; }
        .info td.label { color: #6b7280; width: 22%; }
        .cols td.col { width: 50%; vertical-align: top; padding: 0 6px 0 0; }
        .lines th { background: #f3f4f6; text-align: left; padding: 4px; border: 1px solid #d1d5db; }
        .lines td { padding: 3px 4px; border: 1px solid #e5e7eb; }
        .num { text-align: right; }
        tr.total td { font-weight: bold; background: #f9fafb; }
        .net { margin-top: 12px; padding: 8px; border: 2px solid #0f766e; font-size: 13px; font-weight: bold; }
        .net span { float: right; }
        .employer { margin-top: 10px; color: #4b5563; font-size: 9px; }
        .footer { margin-top: 18px; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
<div class="header">
    <h1>{{ $business['business_name'] ?? config('app.name') }}</h1>
    <div class="meta">
        {{ $business['business_address'] ?? '' }}
        @if(!empty($business['business_phone'])) &middot; {{ $business['business_phone'] }} @endif
        @if(!empty($business['business_tin'])) &middot; TIN {{ $business['business_tin'] }} @endif
    </div>
</div>

<div class="title">{{ __('Payslip for :period', ['period' => \Illuminate\Support\Carbon::parse($run->period.'-01')->translatedFormat('F Y')]) }}</div>

<table class="info">
    <tr>
        <td class="label">{{ __('Employee') }}</td><td><strong>{{ $slip->employee_name }}</strong></td>
        <td class="label">{{ __('Employee No.') }}</td><td>{{ $slip->employee?->employee_no }}</td>
    </tr>
    <tr>
        <td class="label">{{ __('Position') }}</td><td>{{ $slip->position ?? '-' }}</td>
        <td class="label">{{ __('Department') }}</td><td>{{ $slip->department ?? '-' }}</td>
    </tr>
    <tr>
        <td class="label">{{ __('Payment') }}</td><td>{{ __($slip->payment_method ?? '-') }} {{ $slip->bank_name }} {{ $slip->account_hint }}</td>
        <td class="label">{{ __('Days paid') }}</td><td>{{ (float) $slip->days_payable }} @if((float) $slip->unpaid_days > 0) ({{ __('unpaid leave') }}: {{ (float) $slip->unpaid_days }}) @endif</td>
    </tr>
</table>

<br>
<table class="cols"><tr>
    <td class="col">
        <table class="lines">
            <tr><th>{{ __('Earnings') }}</th><th class="num">{{ __('Amount') }}</th></tr>
            <tr><td>{{ __('Basic salary') }}</td><td class="num">{{ $money($slip->basic_salary) }}</td></tr>
            @if((float) $slip->unpaid_deduction > 0)
                <tr><td>{{ __('Less: unpaid leave') }}</td><td class="num">-{{ $money($slip->unpaid_deduction) }}</td></tr>
            @endif
            @foreach($slip->earnings ?? [] as $line)
                <tr><td>{{ $line['name'] }}</td><td class="num">{{ $money($line['amount']) }}</td></tr>
            @endforeach
            <tr class="total"><td>{{ __('Gross pay') }}</td><td class="num">{{ $money($slip->gross) }}</td></tr>
        </table>
    </td>
    <td class="col">
        <table class="lines">
            <tr><th>{{ __('Deductions') }}</th><th class="num">{{ __('Amount') }}</th></tr>
            <tr><td>PAYE ({{ __('taxable') }} {{ $money($slip->taxable_income) }})</td><td class="num">{{ $money($slip->paye) }}</td></tr>
            <tr><td>NSSF</td><td class="num">{{ $money($slip->nssf_employee) }}</td></tr>
            @if((float) $slip->nhif_employee > 0)
                <tr><td>{{ __('Health insurance') }}</td><td class="num">{{ $money($slip->nhif_employee) }}</td></tr>
            @endif
            @foreach($slip->deductions ?? [] as $line)
                <tr><td>{{ $line['name'] }}</td><td class="num">{{ $money($line['amount']) }}</td></tr>
            @endforeach
            @if((float) $slip->advance_recovery > 0)
                <tr><td>{{ __('Salary advance recovery') }}</td><td class="num">{{ $money($slip->advance_recovery) }}</td></tr>
            @endif
            @php($totalDeductions = (float) $slip->paye + (float) $slip->nssf_employee + (float) $slip->nhif_employee + (float) $slip->other_deductions + (float) $slip->advance_recovery)
            <tr class="total"><td>{{ __('Total deductions') }}</td><td class="num">{{ $money($totalDeductions) }}</td></tr>
        </table>
    </td>
</tr></table>

<div class="net">{{ __('Net pay') }} <span>{{ $business['currency'] ?? 'TZS' }} {{ $money($slip->net) }}</span></div>

<div class="employer">
    {{ __('Employer contributions') }}: NSSF {{ $money($slip->nssf_employer) }} &middot; SDL {{ $money($slip->sdl) }} &middot; WCF {{ $money($slip->wcf) }}
    @if((float) $slip->nhif_employer > 0) &middot; {{ __('Health insurance') }} {{ $money($slip->nhif_employer) }} @endif
</div>

<div class="footer">{{ $run->number }} &middot; {{ __('Generated') }} {{ now()->format('d/m/Y H:i') }} &middot; {{ config('mauzo.brand.powered_by') }}</div>
</body>
</html>
