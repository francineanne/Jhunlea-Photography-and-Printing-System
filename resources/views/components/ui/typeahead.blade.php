@props(['url', 'scope' => null, 'name' => 'q', 'value' => '', 'label' => 'Search', 'placeholder' => null, 'id' => null, 'maxlength' => 60, 'icon' => 'search', 'hideLabel' => false, 'inputClass' => ''])

{{--
    Phase 10F: the ONE search box with suggestions (owner New message, Customers, Payments, orders and bookings lists,
    Activity log; the customer's My requests; the public services search). From the first letter it asks the
    suggestion endpoint (200 ms after the last key), shows at most 8 matches with the typed part highlighted, a small type
    label and a grey second line. Up / Down move, Enter opens the highlighted item, Esc closes; Enter with nothing
    highlighted submits the form as before (the full results page). Without JavaScript it is the plain search field of
    the existing form. Everything shown is escaped text; the highlight is added after escaping.
--}}
@php
    $id ??= 'search-'.Str::random(8);
    $endpoint = $scope ? $url.(str_contains($url, '?') ? '&' : '?').'scope='.urlencode($scope) : $url;
@endphp

<div class="ui-field relative w-full" x-data="uiTypeahead(@js($endpoint))" x-on:click.outside="close()" x-on:keydown.escape="close()" data-typeahead>
    <label for="{{ $id }}" @class(['mb-1.5 block text-sm font-semibold', 'sr-only' => $hideLabel])>{{ $label }}</label>
    <div class="relative">
        @if ($icon)
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-app-muted"><x-ui.icon :name="$icon" /></span>
        @endif
        <input id="{{ $id }}" type="search" name="{{ $name }}" value="{{ $value }}" maxlength="{{ $maxlength }}" placeholder="{{ $placeholder }}" autocomplete="off"
            role="combobox" aria-autocomplete="list" aria-controls="{{ $id }}-list" x-bind:aria-expanded="open.toString()"
            x-bind:aria-activedescendant="active >= 0 ? '{{ $id }}-opt-' + active : null"
            x-ref="input" x-on:input="typed()" x-on:focus="typed(true)" x-on:keydown="key($event)"
            {{ $attributes->class([
                'block h-11 w-full rounded-control border border-neutral-200 bg-app-card pr-9 text-base text-app-text transition-[border-color,box-shadow] duration-150 placeholder:text-app-muted field-focus sm:text-sm dark:border-neutral-700',
                'pl-10' => $icon,
                'px-3.5' => ! $icon,
                $inputClass,
            ]) }}>
        {{-- Asking the server --}}
        <span x-show="loading" x-cloak class="absolute right-3 top-1/2 size-2 -translate-y-1/2 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>
    </div>

    <div x-show="open" x-cloak x-transition.opacity.duration.120ms
        class="absolute inset-x-0 top-full z-40 mt-1 max-h-[min(24rem,60dvh)] overflow-y-auto rounded-card border border-app-border bg-app-card p-1 shadow-[0_12px_24px_-6px_rgba(23,23,23,0.18)] thin-scroll dark:shadow-2xl">
        <ul id="{{ $id }}-list" role="listbox" aria-label="{{ $label }}: suggestions">
            <template x-for="(item, index) in items" x-bind:key="index">
                <li x-bind:id="'{{ $id }}-opt-' + index" role="option" x-bind:aria-selected="(index === active).toString()"
                    x-on:mousedown.prevent="choose(item)" x-on:mousemove="active = index"
                    class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg px-2.5 py-1.5"
                    x-bind:class="index === active ? 'bg-neutral-100 dark:bg-white/10' : ''" data-typeahead-option>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold" x-html="mark(item.label)"></span>
                        <span x-show="item.sub" class="block truncate text-xs text-app-text-2" x-html="mark(item.sub)"></span>
                    </span>
                    <span x-show="item.type" class="shrink-0 rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-app-text-2 dark:bg-white/10" x-text="item.type"></span>
                </li>
            </template>
        </ul>
        <p x-show="!loading && items.length === 0" class="px-3 py-2.5 text-sm text-app-text-2" x-text="'No matches for &quot;' + term + '&quot;'"></p>
        <p x-show="items.length > 0" class="hidden px-3 pb-1 pt-1.5 text-[11px] text-app-text-2 sm:block">Enter opens the highlighted one. Esc closes.</p>
    </div>
</div>
