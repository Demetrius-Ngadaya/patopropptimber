@php
    $color = $brand['color'] ?? '#157a55';
    $num = fn ($section, $i) => in_array($i, $section['numeric'] ?? [], true);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $doc['title'] ?? '' }}</title>
    <style>
        @page { margin: 18mm 14mm 16mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9.5px; color: #13213b; }
        .head { border-bottom: 2px solid {{ $color }}; padding-bottom: 8px; margin-bottom: 14px; }
        .head td { vertical-align: middle; }
        .biz { font-size: 15px; font-weight: bold; color: {{ $color }}; }
        .muted { color: #6b7a99; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 11.5px; color: {{ $color }}; margin: 16px 0 5px; }
        p { margin: 0 0 7px; line-height: 1.45; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #f1f4f8; text-align: left; padding: 4px 5px; border-bottom: 1px solid #c2c9d6; font-weight: bold; }
        table.data td { padding: 4px 5px; border-bottom: 1px solid #e3e7ee; }
        table.data tr.total td { font-weight: bold; background: #f6f7fa; border-top: 1px solid #c2c9d6; }
        .r, table.data th.r, table.data td.r { text-align: right; }
        table.meta { border-collapse: collapse; margin: 6px 0 10px; }
        table.meta td { padding: 3px 12px 3px 0; }
        .foot { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 8px; color: #6b7a99; text-align: center; }
    </style>
</head>
<body>
<table class="head" width="100%">
    <tr>
        @if(!empty($brand['logo_path']))
            <td style="width:48px"><img src="{{ $brand['logo_path'] }}" style="width:42px;height:42px;"></td>
        @endif
        <td>
            <div class="biz">{{ $brand['name'] ?? '' }}</div>
            <div class="muted">{{ \App\Services\Messaging\Messenger::contactLine($brand) }}</div>
        </td>
        <td class="r muted">{{ now()->format('d/m/Y H:i') }}</td>
    </tr>
</table>

<h1>{{ $doc['title'] ?? '' }}</h1>
@if(!empty($doc['subtitle']))<p class="muted">{{ $doc['subtitle'] }}</p>@endif

@foreach($doc['intro'] ?? [] as $p)
    <p>{!! nl2br(e($p)) !!}</p>
@endforeach

@if(!empty($doc['meta']))
    <table class="meta">
        @foreach($doc['meta'] as $label => $value)
            <tr><td class="muted">{{ $label }}</td><td><strong>{{ $value }}</strong></td></tr>
        @endforeach
    </table>
@endif

@foreach($doc['sections'] ?? [] as $section)
    <h2>{{ $section['heading'] ?? '' }}</h2>
    @if(empty($section['rows']))
        <p class="muted">{{ $section['empty'] ?? __('Nothing to show.') }}</p>
    @else
        <table class="data">
            <tr>@foreach($section['columns'] as $i => $col)<th class="{{ $num($section, $i) ? 'r' : '' }}">{{ $col }}</th>@endforeach</tr>
            @foreach($section['rows'] as $row)
                <tr>@foreach(array_values($row) as $i => $cell)<td class="{{ $num($section, $i) ? 'r' : '' }}">{{ $cell }}</td>@endforeach</tr>
            @endforeach
            @if(!empty($section['total']))
                <tr class="total">@foreach(array_values($section['total']) as $i => $cell)<td class="{{ $num($section, $i) ? 'r' : '' }}">{{ $cell }}</td>@endforeach</tr>
            @endif
        </table>
    @endif
@endforeach

@foreach($doc['closing'] ?? [] as $p)
    <p style="margin-top:12px">{!! nl2br(e($p)) !!}</p>
@endforeach

<div class="foot">{{ $brand['name'] ?? '' }} · {{ $doc['title'] ?? '' }}<br>{{ config('mauzo.brand.powered_by') }}</div>
</body>
</html>
