{{-- Catalog totals (design: bento stat strip). $stats always covers the whole catalog, not the current search or filter. --}}
@php
    $share = $stats['total'] > 0 ? round($stats['published'] / $stats['total'] * 100) : 0;
    $tile = 'group relative flex animate-fade-up flex-col justify-between overflow-hidden rounded-card border border-app-border bg-app-card p-5 shadow-[0_2px_8px_-2px_rgba(23,23,23,0.04),0_1px_4px_-1px_rgba(23,23,23,0.02)] dark:shadow-none sm:p-6';
    $label = 'text-[11px] font-bold uppercase tracking-wider text-app-text-2';
    $number = 'font-display text-[40px] font-semibold leading-[48px] tracking-tight tabular-nums';
@endphp

<section aria-label="Catalog totals" class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="{{ $tile }}" style="animation-delay: 0ms">
        <span class="pointer-events-none absolute -right-4 -top-4 size-20 rounded-full bg-brand/5 transition-transform duration-500 group-hover:scale-125" aria-hidden="true"></span>
        <div class="flex items-center justify-between gap-2">
            <span class="{{ $label }}">Published</span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 py-0.5 font-mono text-[11px] dark:bg-white/10">
                <span class="size-1.5 rounded-full bg-status-green" aria-hidden="true"></span> LIVE
            </span>
        </div>
        <p class="mt-4 flex items-baseline gap-2">
            <span class="{{ $number }}">{{ $stats['published'] }}</span>
            <span class="text-sm text-app-text-2">of {{ $stats['total'] }} {{ \Illuminate\Support\Str::plural('service', $stats['total']) }}</span>
        </p>
        <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10" role="img" aria-label="{{ $share }} percent of services are published">
            <div class="h-full rounded-full bg-brand" style="width: {{ $share }}%"></div>
        </div>
    </div>

    <div class="{{ $tile }}" style="animation-delay: 80ms">
        <div class="flex items-center justify-between gap-2">
            <span class="{{ $label }}">Not published</span>
            <x-ui.icon name="visibility_off" :size="20" class="text-app-text-2" />
        </div>
        <p class="mt-4 flex items-baseline gap-2">
            <span class="{{ $number }} text-app-text-2">{{ $stats['unpublished'] }}</span>
            <span class="text-sm text-app-text-2">hidden from customers</span>
        </p>
    </div>

    <div class="{{ $tile }}" style="animation-delay: 160ms">
        <div class="flex items-center justify-between gap-2">
            <span class="{{ $label }}">Options</span>
            <x-ui.icon name="tune" :size="20" class="text-app-text-2" />
        </div>
        <p class="mt-4 flex items-baseline gap-2">
            <span class="{{ $number }}">{{ $stats['options'] }}</span>
            <span class="text-sm text-app-text-2">across all services</span>
        </p>
    </div>

    <div class="{{ $tile }}" style="animation-delay: 240ms">
        <div class="flex items-center justify-between gap-2">
            <span class="{{ $label }}">Average base rate</span>
            <x-ui.icon name="payments" :size="20" class="text-app-text-2" />
        </div>
        <div class="mt-4">
            <p class="font-display text-[28px] font-medium leading-9 tabular-nums">{{ \App\Support\Money::format($stats['average']) }}</p>
            <p class="text-sm text-app-text-2">
                @if ($stats['priced'] > 0)
                    across {{ $stats['priced'] }} priced {{ \Illuminate\Support\Str::plural('service', $stats['priced']) }}
                @else
                    no prices set yet
                @endif
            </p>
        </div>
        @if ($stats['priced'] > 0)
            <p class="mt-3 flex flex-wrap justify-between gap-x-3 gap-y-1 font-mono text-[11px] text-app-text-2">
                <span>Min: {{ \App\Support\Money::format($stats['min']) }}</span>
                <span>Max: {{ \App\Support\Money::format($stats['max']) }}</span>
            </p>
        @endif
    </div>
</section>
