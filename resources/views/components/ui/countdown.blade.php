@props(['until', 'prefix' => 'Due'])

{{--
    Phase 10D: a live countdown to a deadline ("Due in 2 days 4 hours"), red when under 24 hours. The server writes the
    same sentence for the first paint and for visitors without scripts; the script only keeps it current each minute.
    The exact date is always shown next to it by the page.
--}}
@php
    $seconds = $until ? now()->diffInSeconds($until, false) : null;
    $say = function (?int $seconds) use ($prefix): string {
        if ($seconds === null) {
            return '';
        }
        if ($seconds <= 0) {
            return 'The deadline has passed';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = $days > 0
            ? [$days.' '.($days === 1 ? 'day' : 'days'), $hours.' '.($hours === 1 ? 'hour' : 'hours')]
            : ($hours > 0 ? [$hours.' '.($hours === 1 ? 'hour' : 'hours'), $minutes.' min'] : [max($minutes, 1).' min']);

        return $prefix.' in '.implode(' ', $parts);
    };
@endphp

@if ($until)
    <span x-data="uiCountdown(@js(\App\Support\LocalTime::iso($until)), @js($prefix))" x-text="text" role="timer" aria-live="off"
        x-bind:class="urgent ? 'bg-status-red-bg text-status-red' : 'bg-status-amber-bg text-status-amber'"
        {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold tabular-nums', $seconds !== null && $seconds < 86400 ? 'bg-status-red-bg text-status-red' : 'bg-status-amber-bg text-status-amber']) }}
        data-countdown>{{ $say($seconds === null ? null : (int) $seconds) }}</span>
@endif
