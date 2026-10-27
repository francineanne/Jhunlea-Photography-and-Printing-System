{{-- Theme library totals. $stats always covers the whole library, not the current search or tab. --}}
@php
    $share = $stats['total'] > 0 ? round($stats['published'] / $stats['total'] * 100) : 0;
    $number = 'font-display text-[40px] font-semibold leading-[48px] tracking-tight tabular-nums';
@endphp

<section aria-label="Theme totals" class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
    <x-ui.stat-tile label="Published backdrops" :delay="0" glow>
        <x-slot:aside>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 py-0.5 font-mono text-[11px] dark:bg-white/10">
                <span class="size-1.5 rounded-full bg-status-green" aria-hidden="true"></span> LIVE
            </span>
        </x-slot:aside>
        <p class="mt-4 flex items-baseline gap-2">
            <span class="{{ $number }}">{{ $stats['published'] }}</span>
            <span class="text-sm text-app-text-2">of {{ $stats['total'] }} {{ \Illuminate\Support\Str::plural('theme', $stats['total']) }}</span>
        </p>
        <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10" role="img" aria-label="{{ $share }} percent of themes are published">
            <div class="h-full rounded-full bg-brand" style="width: {{ $share }}%"></div>
        </div>
    </x-ui.stat-tile>

    <x-ui.stat-tile label="Not published" :delay="80">
        <x-slot:aside><x-ui.icon name="visibility_off" :size="20" class="text-app-text-2" /></x-slot:aside>
        <p class="mt-4 flex items-baseline gap-2">
            <span class="{{ $number }} text-app-text-2">{{ $stats['unpublished'] }}</span>
            <span class="text-sm text-app-text-2">hidden from customers</span>
        </p>
    </x-ui.stat-tile>

    <x-ui.stat-tile label="Used by packages" :delay="160">
        <x-slot:aside><x-ui.icon name="link" :size="20" class="text-app-text-2" /></x-slot:aside>
        <p class="mt-4 flex items-baseline gap-2">
            <span class="{{ $number }}">{{ $stats['in_use'] }}</span>
            <span class="text-sm text-app-text-2">linked to at least one package</span>
        </p>
    </x-ui.stat-tile>
</section>
