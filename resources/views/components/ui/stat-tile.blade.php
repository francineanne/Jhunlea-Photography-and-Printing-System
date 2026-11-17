@props(['label', 'delay' => 0, 'glow' => false])

{{-- One tile of a stat strip (overview, catalog). The slot is the body; the optional `aside` slot sits at the right of the label, or below it when the tile is narrow. --}}
<div {{ $attributes->class('group relative flex animate-fade-up flex-col justify-between overflow-hidden rounded-card border border-app-border bg-app-card p-5 shadow-[0_2px_8px_-2px_rgba(23,23,23,0.04),0_1px_4px_-1px_rgba(23,23,23,0.02)] dark:shadow-none sm:p-6') }} style="animation-delay: {{ (int) $delay }}ms">
    @if ($glow)
        <span class="pointer-events-none absolute -right-4 -top-4 size-20 rounded-full bg-brand/5 transition-transform duration-500 group-hover:scale-125" aria-hidden="true"></span>
    @endif
    {{-- Phase 10E: the label may wrap (balanced) and the badge drops below it when the tile is narrow; never on top of it --}}
    <div class="flex flex-wrap items-start justify-between gap-x-2 gap-y-1.5">
        <span class="eyebrow min-w-0 text-app-text-2">{{ $label }}</span>
        @isset($aside)<span class="shrink-0">{{ $aside }}</span>@endisset
    </div>
    {{ $slot }}
</div>
