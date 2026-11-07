@php
    $color = $brand['color'] ?? '#157a55';
    $m = fn ($n) => number_format((float) $n, 2);
    $show = fn ($c) => in_array($c, $columns, true);
    $stamp = match ($stage) {
        'draft' => __('Draft'),
        'paid' => __('Paid'),
        'cancelled' => __('Cancelled'),
        'expired' => __('Expired'),
        'declined' => __('Declined'),
        default => null,
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $number }}</title>
    <style>
        @page { margin: 16mm 14mm 18mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9.5px; color: #13213b; }
        table { border-collapse: collapse; }
        .muted { color: #6b7a99; }
        .r { text-align: right; }
        .biz { font-size: 15px; font-weight: bold; color: {{ $color }}; }
        .title { font-size: 20px; font-weight: bold; color: {{ $color }}; text-transform: uppercase; letter-spacing: .5px; }
        .box { border: 1px solid #dde2ea; border-radius: 4px; padding: 7px 9px; }
        .label { font-size: 8px; text-transform: uppercase; color: #6b7a99; letter-spacing: .4px; }
        table.items { width: 100%; margin-top: 12px; }
        table.items th { background: {{ $color }}; color: #fff; padding: 5px 6px; text-align: left; font-size: 8.5px; }
        table.items th.r { text-align: right; }
        table.items td { padding: 5px 6px; border-bottom: 1px solid #e6e9ef; vertical-align: top; }
        table.totals td { padding: 3px 6px; }
        table.totals tr.grand td { font-size: 12px; font-weight: bold; border-top: 1.5px solid #13213b; padding-top: 5px; }
        .stamp { position: fixed; top: 38%; left: 18%; font-size: 70px; font-weight: bold; color: rgba(200, 40, 40, .12); transform: rotate(-24deg); text-transform: uppercase; }
        .stamp.paid { color: rgba(21, 122, 85, .14); }
        .section { margin-top: 12px; }
        .sign td { padding-top: 34px; width: 50%; }
        .sign .line { border-top: 1px solid #13213b; width: 80%; padding-top: 3px; }
        .foot { position: fixed; bottom: -10mm; left: 0; right: 0; text-align: center; font-size: 8px; color: #6b7a99; }
    </style>
</head>
<body>
@if($stamp)<div class="stamp {{ $stage === 'paid' ? 'paid' : '' }}">{{ $stamp }}</div>@endif

<table width="100%">
    <tr>
        <td style="vertical-align: top; width: 58%;">
            @if(!empty($brand['logo_path']))<img src="{{ $brand['logo_path'] }}" style="height: 46px; margin-bottom: 4px;"><br>@endif
            <div class="biz">{{ $brand['name'] }}</div>
            @if($brand['address'])<div>{{ $brand['address'] }}</div>@endif
            @if($brand['phone'] || $brand['whatsapp'])<div>{{ collect([$brand['phone'] ? __('Tel').' '.$brand['phone'] : null, $brand['whatsapp'] ? 'WhatsApp '.$brand['whatsapp'] : null])->filter()->implode(' · ') }}</div>@endif
            @if($brand['email'] || $brand['website'])<div>{{ collect([$brand['email'], $brand['website']])->filter()->implode(' · ') }}</div>@endif
            @if($brand['tin'])<div>TIN: <strong>{{ $brand['tin'] }}</strong></div>@endif
            @if($brand['vrn'])<div>VRN: <strong>{{ $brand['vrn'] }}</strong></div>@endif
        </td>
        <td style="vertical-align: top;" class="r">
            <div class="title">{{ $title }}</div>
            <table style="margin-left: auto; margin-top: 6px;">
                <tr><td class="muted r">{{ __('Number') }}</td><td class="r"><strong>{{ $number }}</strong></td></tr>
                <tr><td class="muted r">{{ __('Date') }}</td><td class="r">{{ \Illuminate\Support\Carbon::parse($issue_date)->format('d/m/Y') }}</td></tr>
                @if($due_date)<tr><td class="muted r">{{ $due_label }}</td><td class="r"><strong>{{ \Illuminate\Support\Carbon::parse($due_date)->format('d/m/Y') }}</strong></td></tr>@endif
                @if($branch)<tr><td class="muted r">{{ __('Branch') }}</td><td class="r">{{ $branch }}</td></tr>@endif
            </table>
        </td>
    </tr>
</table>

<table width="100%" style="margin-top: 14px;">
    <tr>
        <td style="width: 55%; vertical-align: top;">
            <div class="box">
                <div class="label">{{ $type === 'quotation' ? __('Prepared for') : __('Bill to') }}</div>
                <div style="font-size: 11px; font-weight: bold; margin-top: 2px;">{{ $customer['name'] }}</div>
                @if($customer['address'])<div>{{ $customer['address'] }}</div>@endif
                @if($customer['phone'] || $customer['email'])<div>{{ collect([$customer['phone'], $customer['email']])->filter()->implode(' · ') }}</div>@endif
                @if($customer['tin'])<div>TIN: {{ $customer['tin'] }}</div>@endif
                @if($customer['vrn'])<div>VRN: {{ $customer['vrn'] }}</div>@endif
            </div>
        </td>
        <td></td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width: 4%;">#</th>
            <th>{{ __('Description') }}</th>
            @if($show('sku'))<th>{{ __('SKU') }}</th>@endif
            <th class="r">{{ __('Qty') }}</th>
            @if($show('unit'))<th>{{ __('Unit') }}</th>@endif
            <th class="r">{{ __('Unit price') }}</th>
            @if($show('discount'))<th class="r">{{ __('Discount') }}</th>@endif
            @if($show('tax'))<th class="r">{{ __('VAT') }}</th>@endif
            <th class="r">{{ __('Amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $i => $item)
            <tr>
                <td class="muted">{{ $i + 1 }}</td>
                <td>{{ $item['description'] }}</td>
                @if($show('sku'))<td class="muted">{{ $item['sku'] }}</td>@endif
                <td class="r">{{ $item['quantity'] }}</td>
                @if($show('unit'))<td>{{ $item['unit'] }}</td>@endif
                <td class="r">{{ $m($item['unit_price']) }}</td>
                @if($show('discount'))<td class="r">{{ $item['discount'] > 0 ? $m($item['discount']) : '' }}</td>@endif
                @if($show('tax'))<td class="r">{{ $item['tax'] > 0 ? $m($item['tax']).' ('.(0 + $item['tax_rate']).'%)' : '' }}</td>@endif
                <td class="r">{{ $m($item['line_total']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table width="100%" style="margin-top: 6px;">
    <tr>
        <td style="width: 55%; vertical-align: top; padding-right: 12px;">
            @if($amount_in_words)
                <div class="label">{{ __('Amount in words') }}</div>
                <div style="font-style: italic;">{{ $amount_in_words }}</div>
            @endif
        </td>
        <td style="vertical-align: top;">
            <table class="totals" width="100%">
                <tr><td class="muted">{{ __('Subtotal') }}</td><td class="r">{{ $m($totals['subtotal']) }}</td></tr>
                @if($totals['item_discount'] + $totals['discount'] > 0)<tr><td class="muted">{{ __('Discount') }}</td><td class="r">-{{ $m($totals['item_discount'] + $totals['discount']) }}</td></tr>@endif
                @if($totals['tax'] > 0)<tr><td class="muted">{{ $totals['prices_include_tax'] ? __('VAT included') : __('VAT') }}</td><td class="r">{{ $m($totals['tax']) }}</td></tr>@endif
                <tr class="grand"><td>{{ __('Total') }} ({{ $currency }})</td><td class="r">{{ $m($totals['total']) }}</td></tr>
                @if($totals['paid'] !== null && $totals['paid'] > 0)<tr><td class="muted">{{ __('Paid') }}</td><td class="r">{{ $m($totals['paid']) }}</td></tr>@endif
                @if($totals['balance'] !== null && $type !== 'quotation' && $stage !== 'draft' && $stage !== 'cancelled')<tr><td><strong>{{ __('Balance due') }}</strong></td><td class="r"><strong>{{ $m($totals['balance']) }}</strong></td></tr>@endif
            </table>
        </td>
    </tr>
</table>

@if($bank_details || $brand['bank'])
    <div class="section box">
        <div class="label">{{ __('How to pay') }}</div>
        <div>{!! nl2br(e($bank_details ?: $brand['bank'])) !!}</div>
    </div>
@endif
@if($terms)
    <div class="section"><div class="label">{{ __('Terms') }}</div><div>{!! nl2br(e($terms)) !!}</div></div>
@endif
@if($notes)
    <div class="section"><div class="label">{{ __('Notes') }}</div><div>{!! nl2br(e($notes)) !!}</div></div>
@endif
@if($decline_reason && $stage === 'declined')
    <div class="section"><div class="label">{{ __('Reason for declining') }}</div><div>{{ $decline_reason }}</div></div>
@endif

@if($signature || $served_by)
    <table width="100%" class="sign">
        <tr>
            <td><div class="line">{{ __('Prepared by') }}@if($served_by): {{ $served_by }}@endif</div></td>
            @if($signature)<td><div class="line">{{ $type === 'quotation' ? __('Accepted by (name, signature, date)') : __('Received by (name, signature, date)') }}</div></td>@endif
        </tr>
    </table>
@endif

<div class="foot">{{ $footer ?: $brand['name'].' · '.$title.' '.$number }}<br>{{ config('mauzo.brand.powered_by') }}</div>
</body>
</html>
