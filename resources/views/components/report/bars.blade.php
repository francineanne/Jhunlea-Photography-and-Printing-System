@props(['series', 'caption', 'unit' => 'Day'])

{{--
    Income over time as vertical bars (Phase 8C). Bars start at zero; the scale is the period's highest slot. A bar
    with income carries its short amount where the bars are wide enough. The full numbers are in the table below,
    which screen readers read instead of the drawing.
--}}
@php
    use App\Support\Money;

    $max = max(1, (int) collect($series)->max('cents'));
    $count = count($series);
    $labelled = $count <= 16;
    $every = $count > 16 ? (int) ceil($count / 10) : 1;
    $everyPhone = max($every, (int) ceil($count / 5));
    $everyPhone = $everyPhone % $every === 0 ? $everyPhone : $every * (int) ceil($everyPhone / $every);
    $total = (int) collect($series)->sum('cents');
    $best = collect($series)->sortByDesc('cents')->first();
@endphp

<figure {{ $attributes }}>
    <div role="img" aria-label="{{ $caption }}: {{ Money::cents($total) }} in all{{ $total > 0 ? ', highest '.Money::cents($best['cents']).' on '.$best['label'] : '' }}. The numbers are in the table below.">
        <div class="relative h-56 sm:h-64" aria-hidden="true">
            {{-- Room at the top for the tallest bar's label --}}
            <div class="absolute inset-x-0 bottom-0 top-6">
            {{-- Gridlines: zero, half and the highest slot --}}
            @foreach ([1, 0.5, 0] as $line)
                <div class="absolute inset-x-0 flex items-center gap-2" style="bottom: {{ $line * 100 }}%">
                    <span class="w-12 shrink-0 -translate-y-1/2 text-right font-mono text-[10px] text-app-text-2 tabular-nums">{{ Money::compact((int) round($max * $line)) }}</span>
                    <span class="h-px flex-1 -translate-y-1/2 {{ $line === 0 ? 'bg-app-text-2/40' : 'border-t border-dashed border-app-border' }}"></span>
                </div>
            @endforeach
            <div class="absolute inset-y-0 left-14 right-0 flex items-end gap-[3px] sm:gap-1.5">
                @foreach ($series as $slot)
                    @php($height = $slot['cents'] > 0 ? max(1.5, round($slot['cents'] / $max * 100, 2)) : 0)
                    <div class="group relative flex h-full min-w-0 flex-1 flex-col items-center justify-end" title="{{ $slot['label'] }}: {{ Money::cents($slot['cents']) }}">
                        @if ($labelled && $slot['cents'] > 0)
                            <span class="mb-1 whitespace-nowrap font-mono text-[10px] font-semibold tabular-nums max-sm:hidden">{{ Money::compact($slot['cents']) }}</span>
                        @endif
                        <span class="chart-a block w-full max-w-10 origin-bottom animate-bar-rise rounded-t-[4px] transition-opacity group-hover:opacity-80" style="height: {{ $height }}%"></span>
                    </div>
                @endforeach
            </div>
            </div>
        </div>
        <div class="ml-14 mt-2 flex gap-[3px] sm:gap-1.5" aria-hidden="true">
            @foreach ($series as $i => $slot)
                {{-- Phones show about five labels; wider screens every label (or every few on a long series) --}}
                <span @class(['flex min-w-0 flex-1 justify-center whitespace-nowrap font-mono text-[10px] text-app-text-2', 'max-sm:invisible' => $i % $everyPhone !== 0])>{{ $i % $every === 0 ? $slot['short'] : '' }}</span>
            @endforeach
        </div>
    </div>

    <details class="group mt-4 rounded-control border border-app-border print:hidden">
        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-3 text-sm font-semibold focus-ring [&::-webkit-details-marker]:hidden">
            <x-ui.icon name="table" :size="18" class="text-app-text-2" />Show the numbers
            <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
        </summary>
        <div class="max-h-72 overflow-y-auto border-t border-app-border">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">{{ $caption }}</caption>
                <thead class="sticky top-0 bg-app-card text-[11px] uppercase tracking-wider text-app-text-2">
                    <tr><th scope="col" class="px-3 py-2">{{ $unit }}</th><th scope="col" class="px-3 py-2 text-right">Payments</th><th scope="col" class="px-3 py-2 text-right">Income</th></tr>
                </thead>
                <tbody class="divide-y divide-app-border">
                    @foreach ($series as $slot)
                        <tr><th scope="row" class="px-3 py-1.5 font-normal">{{ $slot['label'] }}</th><td class="px-3 py-1.5 text-right tabular-nums">{{ $slot['count'] }}</td><td class="px-3 py-1.5 text-right font-semibold tabular-nums">{{ Money::cents($slot['cents']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</figure>
