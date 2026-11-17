@props(['label' => null, 'name' => null, 'id' => null, 'value' => null, 'hint' => null, 'error' => null, 'required' => false, 'step' => 30, 'placeholder' => 'Choose a time'])

{{--
    Phase 10D: the ONE time field of the owner pages. No scrolling wheel: a button showing "9:00 AM" opens a small grid
    of times (AM and PM tabs, four times a row, so nothing scrolls inside it). The form control is a plain <select>
    that posts the same H:i value the server always expected; without JavaScript that select is what the owner uses.
    Keyboard: Enter, Space or Arrow Down opens; arrows, Home and End move; Enter chooses; Esc closes.
--}}
@php
    $id ??= 'field-'.Str::random(8);
    $error ??= ($name && isset($errors)) ? $errors->first($name) : null;
    $step = max(5, min(60, (int) $step));
    $value = filled($value) ? substr((string) $value, 0, 5) : '';
    $times = [];
    for ($m = 0; $m < 1440; $m += $step) {
        $times[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    // A stored time that is not on the grid (an older value) stays selectable
    if ($value !== '' && preg_match('/^\d{2}:\d{2}$/', $value) && ! in_array($value, $times, true)) {
        $times[] = $value;
        sort($times);
    }
    $say = function (string $time): string {
        [$h, $m] = array_map('intval', explode(':', $time));

        return (($h % 12) ?: 12).':'.sprintf('%02d', $m).' '.($h < 12 ? 'AM' : 'PM');
    };
    $options = array_map(fn ($time) => ['value' => $time, 'label' => $say($time), 'pm' => (int) substr($time, 0, 2) >= 12], $times);
@endphp

<x-ui.field :id="$id" :label="$label" :hint="$hint" :error="$error" :required="$required">
    <div class="relative" x-data="uiTimePicker(@js($options), @js($value))" x-on:keydown.escape="close(true)" x-on:click.outside="close(false)" data-time-picker>
        <div class="relative" data-time-select>
            <select id="{{ $id }}" @if ($name) name="{{ $name }}" @endif x-ref="select" x-on:change="value = $el.value"
                @required($required) @if ($error) aria-invalid="true" @endif
                @if ($error || $hint) aria-describedby="{{ $id }}-{{ $error ? 'error' : 'hint' }}" @endif
                {{ $attributes->class([
                    'block h-11 w-full appearance-none rounded-control border bg-app-card pl-3.5 pr-10 text-base text-app-text transition-[border-color,box-shadow] duration-150 sm:text-sm',
                    'field-focus disabled:cursor-not-allowed disabled:opacity-60',
                    'border-neutral-200 dark:border-neutral-700' => ! $error,
                    'border-status-red' => $error,
                ]) }}>
                <option value="">{{ $placeholder }}</option>
                @foreach ($options as $option)
                    <option value="{{ $option['value'] }}" @selected($option['value'] === $value)>{{ $option['label'] }}</option>
                @endforeach
            </select>
            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-app-text-2"><x-ui.icon name="schedule" :size="20" /></span>
        </div>

        {{-- With JavaScript the select above is hidden (it still posts) and this button stands in its place --}}
        <button type="button" x-cloak x-show="true" x-ref="button" x-on:click="toggle()" x-on:keydown.arrow-down.prevent="show()"
            aria-haspopup="dialog" x-bind:aria-expanded="open" aria-controls="{{ $id }}-times"
            x-bind:aria-label="@js(($label ?? 'Time').': ') + (value ? text(value) : @js($placeholder))"
            @class([
                'flex h-11 w-full items-center justify-between gap-2 rounded-control border bg-app-card pl-3.5 pr-3 text-left text-base tabular-nums text-app-text transition-[border-color,box-shadow] duration-150 field-focus sm:text-sm',
                'border-neutral-200 dark:border-neutral-700' => ! $error,
                'border-status-red' => $error,
            ])>
            <span class="truncate whitespace-nowrap" x-text="value ? text(value) : @js($placeholder)" x-bind:class="value || 'text-app-muted'"></span>
            <x-ui.icon name="schedule" :size="20" class="shrink-0 text-app-text-2" />
        </button>

        <div id="{{ $id }}-times" x-cloak x-show="open" x-transition.opacity.duration.150ms role="dialog" aria-label="{{ $label ?? 'Time' }}: choose a time"
            class="absolute left-0 top-full z-30 mt-1 w-72 max-w-[calc(100vw-2rem)] rounded-card border border-app-border bg-app-card p-3 shadow-[0_12px_24px_-6px_rgba(23,23,23,0.18)] dark:shadow-2xl"
            x-bind:class="flip && 'left-auto! right-0'">
            <div class="mb-2 grid grid-cols-2 gap-1 rounded-control bg-neutral-100 p-1 dark:bg-white/5" role="group" aria-label="Part of the day">
                <button type="button" x-on:click="pm = false" x-bind:aria-pressed="!pm" class="min-h-11 rounded-lg text-sm font-semibold focus-ring sm:min-h-9" x-bind:class="!pm ? 'bg-app-card text-app-text shadow-sm' : 'text-app-text-2'">AM</button>
                <button type="button" x-on:click="pm = true" x-bind:aria-pressed="pm" class="min-h-11 rounded-lg text-sm font-semibold focus-ring sm:min-h-9" x-bind:class="pm ? 'bg-app-card text-app-text shadow-sm' : 'text-app-text-2'">PM</button>
            </div>
            <div class="grid grid-cols-4 gap-1" x-ref="grid" x-on:keydown="move($event)">
                <template x-for="option in shown" x-bind:key="option.value">
                    <button type="button" x-on:click="choose(option.value)" x-bind:data-time="option.value" x-bind:aria-pressed="option.value === value"
                        x-bind:tabindex="option.value === (value && shown.some((o) => o.value === value) ? value : shown[0].value) ? 0 : -1"
                        class="min-h-11 rounded-lg text-[13px] font-semibold tabular-nums transition-colors duration-150 focus-ring sm:min-h-9"
                        x-bind:class="option.value === value ? 'bg-brand text-white' : 'text-app-text hover:bg-black/5 dark:hover:bg-white/10'"
                        x-text="option.label.replace(' AM', '').replace(' PM', '')" x-bind:aria-label="option.label"></button>
                </template>
            </div>
            @unless ($required)
                <button type="button" x-show="value" x-on:click="choose('')" class="mt-2 inline-flex min-h-11 items-center gap-1 rounded-control px-2 text-sm font-semibold text-app-text-2 hover:text-app-text focus-ring sm:min-h-9"><x-ui.icon name="backspace" :size="16" />Clear</button>
            @endunless
        </div>
    </div>
</x-ui.field>
