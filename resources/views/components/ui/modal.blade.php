@props(['name', 'title'])

{{-- Open from anywhere with x-on:click="$dispatch('open-modal', '{{ $name }}')". Body is the default slot; buttons go in the `footer` slot. --}}
<div x-data="uiModal(@js($name))" x-on:open-modal.window="show($event)" x-on:close-modal.window="hide()"
    x-on:keydown.escape.window="hide()" x-on:keydown.tab="trap($event)" x-on:focusin.window="guard($event)" x-show="open" x-cloak
    class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
    <div x-show="open" x-transition.opacity.duration.280ms x-on:click="hide()" class="absolute inset-0 bg-ink/50 backdrop-blur-[12px]" aria-hidden="true"></div>

    <div x-ref="panel" x-show="open" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="modal-title-{{ $name }}"
        x-transition:enter="transition duration-[280ms] ease-brand" x-transition:enter-start="translate-y-4 opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100" x-transition:leave="transition duration-150 ease-brand"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
        {{ $attributes->class('relative flex max-h-[calc(100dvh-2rem)] w-full max-w-lg flex-col rounded-card border border-app-border bg-app-card/95 shadow-2xl backdrop-blur-[16px] focus:outline-none') }}>
        <div class="flex items-start justify-between gap-3 border-b border-app-border py-3 pl-5 pr-2 sm:pl-6">
            <h2 id="modal-title-{{ $name }}" class="py-2 font-display text-xl font-semibold">{{ $title }}</h2>
            <button type="button" x-on:click="hide()" aria-label="Close dialog"
                class="grid size-11 shrink-0 place-items-center rounded-control text-app-text-2 hover:bg-black/5 hover:text-app-text focus-ring dark:hover:bg-white/10">
                <x-ui.icon name="close" />
            </button>
        </div>

        <div class="overflow-y-auto px-5 py-5 text-sm sm:px-6">{{ $slot }}</div>

        @isset($footer)
            <div class="flex flex-col-reverse gap-2 border-t border-app-border px-5 py-4 sm:flex-row sm:justify-end sm:px-6">{{ $footer }}</div>
        @endisset
    </div>
</div>
