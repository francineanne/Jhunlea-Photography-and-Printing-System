@props(['tone' => 'gray', 'dot' => true])

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
        <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
