@props(['compact' => false])

{{--
    Phase 10K: "Notification sound" on or off (a soft chime when a new notification arrives). Kept in this browser only
    (localStorage, ON by default); every copy on the page follows the same choice. Needs JavaScript, so it is not shown
    without it (the chime needs it too).
--}}
<div x-data="soundSwitch" x-cloak {{ $attributes->class(['flex items-center justify-between gap-3', 'px-4 py-1' => $compact]) }} data-sound-switch>
    <span class="flex min-w-0 items-center gap-2 text-sm">
        <x-ui.icon name="volume_up" :size="18" class="shrink-0 text-app-text-2" x-show="on" />
        <x-ui.icon name="volume_off" :size="18" class="shrink-0 text-app-text-2" x-show="! on" />
        <span id="{{ $id = 'sound-'.\Illuminate\Support\Str::random(6) }}">Notification sound</span>
    </span>
    <button type="button" role="switch" x-on:click="toggle()" x-bind:aria-checked="on.toString()" aria-labelledby="{{ $id }}"
        class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control focus-ring">
        <span class="relative h-6 w-11 rounded-full transition-colors duration-200" x-bind:class="on ? 'bg-status-green' : 'bg-neutral-300 dark:bg-neutral-600'">
            <span class="absolute top-0.5 size-5 rounded-full bg-white shadow transition-[left] duration-200" x-bind:class="on ? 'left-[22px]' : 'left-0.5'"></span>
        </span>
        <span class="sr-only" x-text="on ? 'On' : 'Off'"></span>
    </button>
</div>
