@props(['label' => 'Filters', 'active' => 0])

{{--
    Phase 10D: on phones the filters of a list fold into one "Filters" button that opens a bottom sheet, so the results
    come right after the page header. From 640px, and without JavaScript, the panel is simply on the page.
    active: how many filters are set (shown on the button).
--}}
<div x-data="uiFilterSheet" x-on:keydown.escape.window="hide()" {{ $attributes }}>
    <button type="button" x-cloak x-show="true" x-on:click="show()" aria-haspopup="dialog" x-bind:aria-expanded="open"
        class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control border border-app-border bg-app-card px-4 text-sm font-semibold focus-ring sm:hidden!">
        <x-ui.icon name="tune" :size="20" />{{ $label }}
        @if ($active > 0)
            <span class="rounded-full bg-brand px-2 py-0.5 text-[11px] font-bold tabular-nums text-white">{{ $active }}<span class="sr-only"> set</span></span>
        @endif
    </button>

    <div x-cloak x-show="open" x-transition.opacity.duration.150ms x-on:click="hide()" class="fixed inset-0 z-50 bg-ink/50 backdrop-blur-sm sm:hidden!" aria-hidden="true"></div>

    <div x-ref="sheet" class="filter-sheet" x-bind:class="open && 'is-open'" x-bind:role="open ? 'dialog' : null" x-bind:aria-modal="open ? 'true' : null" aria-label="{{ $label }}">
        <div x-cloak x-show="open" class="mb-3 flex items-center justify-between gap-3 sm:hidden!">
            <p class="font-display text-lg font-semibold">{{ $label }}</p>
            <button type="button" x-on:click="hide()" aria-label="Close {{ strtolower($label) }}" class="grid size-11 place-items-center rounded-control text-app-text-2 hover:text-app-text focus-ring"><x-ui.icon name="close" /></button>
        </div>
        {{ $slot }}
    </div>
</div>
