@props(['name', 'icon' => 'lightbulb'])

{{--
    Phase 10D: a one-time hint, shown the first time something matters and never again once it is closed (remembered
    in this browser only; storage failing just means it shows again). Inline, never a pop-up over the page. Without
    JavaScript it simply stays visible.
--}}
<div x-data="uiHint(@js($name))" x-show="show" x-transition.opacity.duration.200ms role="note"
    {{ $attributes->class('flex items-start gap-2 rounded-control border border-status-blue/25 bg-status-blue-bg p-3 text-sm') }} data-hint="{{ $name }}">
    <x-ui.icon :name="$icon" :size="18" class="mt-0.5 shrink-0 text-status-blue" />
    <p class="min-w-0 flex-1">{{ $slot }}</p>
    <button type="button" x-cloak x-show="true" x-on:click="dismiss()" aria-label="Got it, hide this tip" title="Got it, hide this tip"
        class="-my-2 -mr-1 grid size-11 shrink-0 place-items-center rounded-control text-app-text-2 hover:text-app-text focus-ring">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span>
    </button>
</div>
