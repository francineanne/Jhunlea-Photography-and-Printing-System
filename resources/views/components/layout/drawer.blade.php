@props(['name', 'label', 'side' => 'left', 'closeAt' => 1024])

{{-- Slide-in panel built on the same Alpine behaviour as the modal (ESC, click outside, focus trap, focus return).
     Open with x-on:click="$dispatch('open-modal', '{{ $name }}')". It closes by itself when the screen grows past `closeAt` px. --}}
@php $left = $side === 'left'; @endphp

<div x-data="uiModal(@js($name))" x-on:open-modal.window="show($event)" x-on:close-modal.window="hide()"
    x-on:keydown.escape.window="hide()" x-on:keydown.tab="trap($event)" x-on:focusin.window="guard($event)"
    x-on:resize.window="innerWidth >= {{ (int) $closeAt }} && hide()" x-show="open" x-cloak
    {{ $attributes->class('fixed inset-0 z-50') }}>
    <div x-show="open" x-transition.opacity.duration.280ms x-on:click="hide()" class="absolute inset-0 bg-ink/50 backdrop-blur-[12px]" aria-hidden="true"></div>

    <div x-ref="panel" x-show="open" tabindex="-1" role="dialog" aria-modal="true" aria-label="{{ $label }}"
        x-transition:enter="transition duration-[280ms] ease-brand"
        x-transition:enter-start="{{ $left ? '-translate-x-full' : 'translate-x-full' }}" x-transition:enter-end="translate-x-0"
        x-transition:leave="transition duration-150 ease-brand"
        x-transition:leave-start="translate-x-0" x-transition:leave-end="{{ $left ? '-translate-x-full' : 'translate-x-full' }}"
        @class([
            'absolute inset-y-0 flex w-[min(20rem,85vw)] flex-col overflow-y-auto bg-app-bg p-4 text-app-text shadow-2xl focus:outline-none',
            'left-0 border-r border-app-border' => $left,
            'right-0 border-l border-app-border' => ! $left,
        ])>
        <div class="mb-4 flex items-center justify-between gap-2">
            <div class="min-w-0">{{ $heading ?? '' }}</div>
            <button type="button" x-on:click="hide()" aria-label="Close menu"
                class="grid size-11 shrink-0 place-items-center rounded-control text-app-text-2 hover:bg-black/5 hover:text-app-text focus-ring dark:hover:bg-white/10">
                <x-ui.icon name="close" />
            </button>
        </div>

        {{ $slot }}
    </div>
</div>
