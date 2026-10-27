{{-- Package totals. $stats always covers the whole catalog, not the current search or filter. --}}
@php
    $share = $stats['total'] > 0 ? round($stats['published'] / $stats['total'] * 100) : 0;
    $number = 'font-display text-[40px] font-semibold leading-[48px] tracking-tight tabular-nums';
@endphp

<section aria-label="Package totals" class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <x-ui.stat-tile label="Published" :delay="0" glow>
        <x-slot:aside>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 py-0.5 font-mono text-[11px] dark:bg-white/10">
                <span class="size-1.5 rounded-full bg-status-green" aria-hidden="true"></span> LIVE
            </span>
        </x-slot:aside>
        <p class="mt-4 flex items-baseline gap-2">
            <span class="{{ $number }}">{{ $stats['published'] }}</span>
            <span class="text-sm text-app-text-2">of {{ $stats['total'] }} {{ \Illuminate\Support\Str::plural('package', $stats['total']) }}</span>
        </p>
        <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10" role="img" aria-label="{{ $share }} percent of packages are published">
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

    <x-ui.stat-tile label="Featured" :delay="160">
        <x-slot:aside><x-ui.icon name="star" :size="20" class="text-brand" /></x-slot:aside>
        <p class="mt-4 flex items-baseline gap-2">
            <span class="{{ $number }}">{{ $stats['featured'] }}</span>
            <span class="text-sm text-app-text-2">shown first to customers</span>
        </p>
    </x-ui.stat-tile>

    <x-ui.stat-tile label="Average listed price" :delay="240">
        <x-slot:aside><x-ui.icon name="payments" :size="20" class="text-app-text-2" /></x-slot:aside>
        <div class="mt-4">
            <p class="font-display text-[28px] font-medium leading-9 tabular-nums">{{ \App\Support\Money::format($stats['average']) }}</p>
            <p class="text-sm text-app-text-2">
                @if ($stats['priced'] > 0)
                    across {{ $stats['priced'] }} priced {{ \Illuminate\Support\Str::plural('package', $stats['priced']) }}
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
    </x-ui.stat-tile>
</section>
