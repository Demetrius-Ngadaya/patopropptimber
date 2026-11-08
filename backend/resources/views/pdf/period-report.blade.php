<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #111827; }
        h1 { font-size: 15px; color: #0f766e; margin: 0; }
        h2 { font-size: 12px; margin: 14px 0 4px; border-bottom: 1px solid #d1d5db; }
        h3 { font-size: 10px; margin: 8px 0 3px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th { background: #f3f4f6; text-align: left; padding: 3px; border: 1px solid #d1d5db; }
        td { padding: 3px; border: 1px solid #e5e7eb; }
        .num { text-align: right; }
        .muted { color: #6b7280; }
        .total td { font-weight: bold; background: #f9fafb; }
    </style>
</head>
<body>
<h1>{{ $business }}</h1>
<div class="muted">{{ \App\Services\Messaging\Messenger::contactLine($brand ?? []) }}</div>
<div class="muted">{{ $title }} &middot; {{ $period }}</div>

<h2>Summary</h2>
<table>
    <tr><th>Branch</th><th class="num">Sales</th><th class="num">Credit (unpaid)</th><th class="num">Debts collected</th><th class="num">Expenses</th></tr>
    @foreach($branches as $b)
        <tr>
            <td>{{ $b['name'] }}</td>
            <td class="num">{{ number_format($b['sales'], 2) }}</td>
            <td class="num">{{ number_format($b['credit_unpaid'], 2) }}</td>
            <td class="num">{{ number_format($b['credit_paid'], 2) }}</td>
            <td class="num">{{ number_format($b['expenses'], 2) }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td>All branches</td>
        <td class="num">{{ number_format($totals['sales'], 2) }}</td>
        <td class="num">{{ number_format($totals['credit_unpaid'], 2) }}</td>
        <td class="num">{{ number_format($totals['credit_paid'], 2) }}</td>
        <td class="num">{{ number_format($totals['expenses'], 2) }}</td>
    </tr>
</table>

@foreach($branches as $b)
    <h2>{{ $b['name'] }}</h2>
    <h3>Sales</h3>
    <table>
        <tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Cashier</th><th>Payment</th><th class="num">Total</th><th class="num">Balance</th></tr>
        @forelse($b['sales_rows'] as $s)
            <tr>
                <td>{{ $s->sold_at->format('d/m/Y H:i') }}</td>
                <td>{{ $s->number }}</td>
                <td>{{ $s->customer?->name ?? $s->customer_name ?? __('Customer') }}</td>
                <td>{{ $s->cashier?->name }}</td>
                <td>{{ $s->payment_status }}</td>
                <td class="num">{{ number_format((float) $s->total, 2) }}</td>
                <td class="num">{{ number_format((float) $s->balance, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No sales in this period.</td></tr>
        @endforelse
    </table>
    <h3>Expenses</h3>
    <table>
        <tr><th>Date</th><th>Expense</th><th>Description</th><th>Recorded by</th><th class="num">Amount</th></tr>
        @forelse($b['expense_rows'] as $e)
            <tr>
                <td>{{ $e->expense_date->format('d/m/Y') }}</td>
                <td>{{ $e->title }}</td>
                <td>{{ $e->description }}</td>
                <td>{{ $e->creator?->name }}</td>
                <td class="num">{{ number_format((float) $e->amount, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No expenses in this period.</td></tr>
        @endforelse
    </table>
@endforeach
<p style="margin-top:14px;font-size:8px;color:#9ca3af;text-align:center">{{ config('mauzo.brand.powered_by') }}</p>
</body>
</html>
