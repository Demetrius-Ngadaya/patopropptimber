<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #111827; margin: 0; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 6px; margin-bottom: 8px; }
        .header h1 { font-size: 15px; margin: 0; color: #0f766e; }
        .header .meta { color: #4b5563; font-size: 9px; }
        h2 { font-size: 12px; margin: 6px 0; }
        .filters { color: #4b5563; margin-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f3f4f6; text-align: left; padding: 4px; border: 1px solid #d1d5db; font-size: 8.5px; }
        td { padding: 3px 4px; border: 1px solid #e5e7eb; vertical-align: top; }
        td.num, th.num { text-align: right; }
        tr.total td { font-weight: bold; background: #f9fafb; }
        .empty { text-align: center; color: #6b7280; padding: 12px; }
        .footer { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 8px; color: #9ca3af; text-align: right; }
    </style>
</head>
<body>
<div class="header">
    <h1>{{ $business['business_name'] ?? config('app.name') }}</h1>
    {{-- Only the company details chosen for reports (Settings → Invoices & documents). --}}
    <div class="meta">{{ \App\Services\Messaging\Messenger::contactLine(app(\App\Services\Messaging\Messenger::class)->brand('report')) }}</div>
</div>

<h2>{{ $title }}</h2>
<div class="filters">Generated {{ now()->format('d/m/Y H:i') }} @if($filters) &middot; {{ $filters }} @endif</div>

<table>
    <thead>
    <tr>
        @foreach($columns as $column)
            <th class="{{ in_array($column['type'], ['money', 'number']) ? 'num' : '' }}">{{ $column['label'] }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @forelse($rows as $row)
        <tr>
            @foreach($columns as $key => $column)
                <td class="{{ in_array($column['type'], ['money', 'number']) ? 'num' : '' }}">{{ $row[$key] }}</td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($columns) }}" class="empty">No records for the selected filters.</td></tr>
    @endforelse
    @if(!empty($totals))
        <tr class="total">
            @foreach($columns as $key => $column)
                <td class="num">{{ $loop->first ? 'TOTAL' : ($totals[$key] ?? '') }}</td>
            @endforeach
        </tr>
    @endif
    </tbody>
</table>

<div class="footer">{{ config('mauzo.brand.powered_by') }} &middot; {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
