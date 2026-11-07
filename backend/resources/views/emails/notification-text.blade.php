{{ $brand['name'] ?? '' }}

{{ $doc['title'] ?? '' }}
@if(!empty($doc['subtitle'])){{ $doc['subtitle'] }}
@endif

@foreach($doc['intro'] ?? [] as $p)
{{ $p }}

@endforeach
@foreach($doc['meta'] ?? [] as $label => $value)
{{ $label }}: {{ $value }}
@endforeach
@foreach($doc['sections'] ?? [] as $section)

== {{ $section['heading'] ?? '' }} ==
@forelse($section['rows'] ?? [] as $row)
- {{ implode(' | ', array_map('strval', array_values($row))) }}
@empty
{{ $section['empty'] ?? __('Nothing to show.') }}
@endforelse
@if(!empty($section['total']))
= {{ implode(' | ', array_map('strval', array_values($section['total']))) }}
@endif
@endforeach
@foreach($doc['closing'] ?? [] as $p)

{{ $p }}
@endforeach

{{ __('The same details are attached as a PDF.') }}

{{ config('mauzo.brand.powered_by') }}
