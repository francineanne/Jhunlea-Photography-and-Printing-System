@props(['steps', 'current' => 1])

{{-- `current` is 1-based. Bind it from a parent with x-model, e.g. <x-ui.stepper :steps="$steps" x-model="step" /> --}}
@php
    $total = count($steps);
@endphp

<div x-data="{ current: {{ (int) $current }}, total: {{ $total }}, labels: @js(array_values($steps)) }" x-modelable="current" {{ $attributes }}>
    {{-- Phones: current step label and a compact progress bar --}}
    <div class="sm:hidden">
        <p class="flex items-baseline justify-between gap-3 text-sm">
            <span class="font-semibold" x-text="labels[current - 1]"></span>
            <span class="shrink-0 font-mono text-xs text-app-text-2">Step <span x-text="current"></span> of {{ $total }}</span>
        </p>
        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-app-border" role="progressbar" aria-valuemin="1"
            aria-valuemax="{{ $total }}" x-bind:aria-valuenow="current" x-bind:aria-valuetext="labels[current - 1]">
            <div class="h-full rounded-full bg-brand transition-[width] duration-500 ease-brand" x-bind:style="`width: ${(current / total) * 100}%`"></div>
        </div>
    </div>

    {{-- Tablet and up: numbered steps joined by a progress line that fills as the step advances --}}
    <nav class="relative hidden sm:block" aria-label="Progress">
        <div class="absolute top-4 h-0.5 bg-app-border" style="left: {{ 50 / $total }}%; right: {{ 50 / $total }}%" aria-hidden="true">
            <div class="h-full bg-brand transition-[width] duration-500 ease-brand"
                x-bind:style="`width: ${Math.min(Math.max((current - 1) / Math.max(total - 1, 1), 0), 1) * 100}%`"></div>
        </div>

        <ol class="relative grid" style="grid-template-columns: repeat({{ $total }}, minmax(0, 1fr))">
            @foreach (array_values($steps) as $index => $label)
                @php $number = $index + 1; @endphp
                <li class="flex flex-col items-center gap-2 px-1 text-center" x-bind:aria-current="current === {{ $number }} ? 'step' : false">
                    <span class="grid size-8 place-items-center rounded-full border-2 text-sm font-semibold transition-colors duration-[280ms] ease-brand"
                        x-bind:class="current > {{ $number }} ? 'border-brand bg-brand text-white' : (current === {{ $number }} ? 'border-brand bg-app-card text-brand' : 'border-app-border bg-app-card text-app-muted')">
                        <x-ui.icon name="check" :size="18" x-show="current > {{ $number }}" x-cloak />
                        <span x-show="current <= {{ $number }}">{{ $number }}</span>
                    </span>
                    <span class="text-xs font-semibold sm:text-sm" x-bind:class="current >= {{ $number }} ? 'text-app-text' : 'text-app-text-2'">{{ $label }}</span>
                </li>
            @endforeach
        </ol>
    </nav>
</div>
