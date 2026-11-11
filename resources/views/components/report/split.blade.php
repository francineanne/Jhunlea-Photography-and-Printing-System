@props(['parts', 'caption'])

{{--
    A two-part split of the period's income (Phase 8C), after the payment-methods bar of the reports design: one bar
    in two segments, then a tile per part with its name, amount, count and share. The second part is striped, so the
    two never differ by color alone; the tiles carry every number in words. `parts` is two arrays of
    label, icon, cents, count.
--}}
@php
    use App\Support\Money;

    $total = array_sum(array_column($parts, 'cents'));
    $share = fn (int $cents) => $total > 0 ? round($cents / $total * 100, 1) : 0;
    $styles = ['chart-a', 'chart-b'];
@endphp

<figure {{ $attributes }}>
    <figcaption class="sr-only">{{ $caption }}</figcaption>
    <div role="img" aria-label="{{ $caption }}: {{ collect($parts)->map(fn ($part) => $part['label'].' '.Money::cents($part['cents']).' ('.$share($part['cents']).'%)')->implode(', ') }}">
        <div class="flex h-3 overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10" aria-hidden="true">
            @foreach ($parts as $i => $part)
                @if ($part['cents'] > 0)
                    <span class="{{ $styles[$i] }} block h-full origin-left animate-bar-grow" style="width: {{ $share($part['cents']) }}%"></span>
                @endif
            @endforeach
        </div>
        <div class="mt-1.5 flex justify-between gap-2 font-mono text-[10px] text-app-text-2" aria-hidden="true">
            @foreach ($parts as $part)<span>{{ $part['label'] }} {{ $share($part['cents']) }}%</span>@endforeach
        </div>
    </div>
    {{-- The same numbers in words (what a screen reader and the printed page read) --}}
    <ul class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-1 2xl:grid-cols-2" aria-label="{{ $caption }}, in numbers">
        @foreach ($parts as $i => $part)
            <li class="flex items-center gap-3 rounded-control border border-app-border bg-app-bg/60 p-3">
                <span class="{{ $styles[$i] }} size-3 shrink-0 rounded-sm" aria-hidden="true"></span>
                <x-ui.icon :name="$part['icon']" :size="18" class="shrink-0 text-app-text-2" />
                <span class="min-w-0 flex-1">
                    <span class="block break-words text-sm font-semibold">{{ $part['label'] }}</span>
                    <span class="block text-xs text-app-text-2">{{ $part['count'] }} {{ Str::plural('payment', $part['count']) }}</span>
                </span>
                <span class="shrink-0 text-right">
                    <span class="block font-bold tabular-nums">{{ Money::cents($part['cents']) }}</span>
                    <span class="block text-xs text-app-text-2">{{ $share($part['cents']) }}%</span>
                </span>
            </li>
        @endforeach
    </ul>
</figure>
