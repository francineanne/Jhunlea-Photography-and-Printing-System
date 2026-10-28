@props(['value', 'format' => \App\Support\LocalTime::DATE_TIME])

{{-- A stored UTC date shown in the shop's time zone. The machine-readable value stays in UTC. --}}
@if ($value)
    <time datetime="{{ \App\Support\LocalTime::iso($value) }}" {{ $attributes }}>{{ \App\Support\LocalTime::format($value, $format) }}</time>
@else
    <span {{ $attributes }}>—</span>
@endif
