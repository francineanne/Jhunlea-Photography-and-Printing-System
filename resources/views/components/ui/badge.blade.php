@props(['tone' => 'gray', 'dot' => true, 'pulse' => null])

@php
    $tones = [
        'amber' => 'bg-status-amber-bg text-status-amber',
        'blue' => 'bg-status-blue-bg text-status-blue',
        'violet' => 'bg-status-violet-bg text-status-violet',
        'green' => 'bg-status-green-bg text-status-green',
        'gray' => 'bg-status-gray-bg text-status-gray',
        'red' => 'bg-status-red-bg text-status-red',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold', $tones[$tone] ?? $tones['gray']]) }}>
    @if ($dot)
        {{-- pulse: the dot breathes (work in progress); ping: a ring spreads from it (waiting for the customer), as in the order designs --}}
        <span class="relative flex size-1.5" aria-hidden="true">
            @if ($pulse === 'ping')
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-75"></span>
            @endif
            <span @class(['relative size-1.5 rounded-full bg-current', 'animate-pulse' => $pulse === 'pulse'])></span>
        </span>
    @endif
    {{ $slot }}
</span>
