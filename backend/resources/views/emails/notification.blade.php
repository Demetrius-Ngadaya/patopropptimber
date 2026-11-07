@php
    $color = $brand['color'] ?? '#157a55';
    $num = fn ($section, $i) => in_array($i, $section['numeric'] ?? [], true);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $doc['title'] ?? '' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#13213b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #dde2ea;">
            <tr>
                <td style="background:{{ $color }};padding:18px 24px;color:#ffffff;">
                    @if(!empty($brand['logo_url']))
                        <img src="{{ $brand['logo_url'] }}" alt="" width="40" height="40" style="border-radius:20px;vertical-align:middle;margin-right:10px;background:#fff;">
                    @endif
                    <span style="font-size:17px;font-weight:600;vertical-align:middle;">{{ $brand['name'] ?? '' }}</span>
                </td>
            </tr>
            <tr>
                <td style="padding:24px;">
                    <h1 style="margin:0 0 4px;font-size:20px;line-height:1.3;">{{ $doc['title'] ?? '' }}</h1>
                    @if(!empty($doc['subtitle']))<p style="margin:0 0 16px;color:#6b7a99;font-size:13px;">{{ $doc['subtitle'] }}</p>@endif

                    @foreach($doc['intro'] ?? [] as $p)
                        <p style="margin:0 0 12px;font-size:14px;line-height:1.55;">{!! nl2br(e($p)) !!}</p>
                    @endforeach

                    @if(!empty($doc['meta']))
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 18px;border:1px solid #edf0f5;border-radius:8px;">
                            @foreach($doc['meta'] as $label => $value)
                                <tr>
                                    <td style="padding:8px 12px;font-size:13px;color:#6b7a99;border-bottom:1px solid #edf0f5;width:42%;">{{ $label }}</td>
                                    <td style="padding:8px 12px;font-size:13px;font-weight:600;border-bottom:1px solid #edf0f5;">{{ $value }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif

                    @foreach($doc['sections'] ?? [] as $section)
                        <h2 style="margin:22px 0 8px;font-size:15px;color:{{ $color }};">{{ $section['heading'] ?? '' }}</h2>
                        @if(empty($section['rows']))
                            <p style="margin:0 0 8px;font-size:13px;color:#6b7a99;">{{ $section['empty'] ?? __('Nothing to show.') }}</p>
                        @else
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:12.5px;">
                                <tr>
                                    @foreach($section['columns'] as $i => $col)
                                        <th style="text-align:{{ $num($section, $i) ? 'right' : 'left' }};padding:7px 8px;background:#f6f7fa;border-bottom:1px solid #dde2ea;color:#4a5d82;font-weight:600;">{{ $col }}</th>
                                    @endforeach
                                </tr>
                                @foreach($section['rows'] as $row)
                                    <tr>
                                        @foreach(array_values($row) as $i => $cell)
                                            <td style="text-align:{{ $num($section, $i) ? 'right' : 'left' }};padding:7px 8px;border-bottom:1px solid #edf0f5;">{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                                @if(!empty($section['total']))
                                    <tr>
                                        @foreach(array_values($section['total']) as $i => $cell)
                                            <td style="text-align:{{ $num($section, $i) ? 'right' : 'left' }};padding:8px;font-weight:700;background:#f6f7fa;">{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                @endif
                            </table>
                        @endif
                    @endforeach

                    @foreach($doc['closing'] ?? [] as $p)
                        <p style="margin:16px 0 0;font-size:14px;line-height:1.55;">{!! nl2br(e($p)) !!}</p>
                    @endforeach
                    <p style="margin:20px 0 0;font-size:12px;color:#6b7a99;">{{ __('The same details are attached as a PDF.') }}</p>
                </td>
            </tr>
            <tr>
                <td style="padding:14px 24px;background:#f6f7fa;font-size:11.5px;color:#6b7a99;border-top:1px solid #edf0f5;">
                    {{ $brand['name'] ?? '' }}@php($contact = \App\Services\Messaging\Messenger::contactLine($brand))@if($contact) · {{ $contact }}@endif
                    <br>{{ __('Sent automatically by :product on :date.', ['product' => config('mauzo.brand.company').' '.config('mauzo.brand.product'), 'date' => now()->format('d/m/Y H:i')]) }}
                </td>
            </tr>
        </table>
    </td></tr>
</table>
</body>
</html>
