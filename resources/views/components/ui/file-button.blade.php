@props(['for', 'label' => 'Choose files', 'variant' => 'primary', 'icon' => 'upload_file', 'chosen' => true, 'empty' => 'No files yet'])

{{--
    Phase 10N: the ONE file picker button. Put it right AFTER the native <input type="file" class="peer sr-only" id="...">
    in the same parent: the input stays in the page (focusable, labelled, it is what Tab reaches and what Enter or Space
    opens, and what a form posts without JavaScript), and this label is what is seen and clicked. The focus ring follows
    the input (peer-focus-visible), so there is no browser "Choose File / No file chosen" text anywhere.
    chosen: a small muted line under the button with the chosen file name(s) ("No files yet" before), filled by the
    fileButtons listener in polish.js; off where the page lists the files itself (the printing and booking wizards).
--}}
@php
    $variants = [
        'primary' => 'btn-sweep text-white',
        'outline' => 'border border-neutral-200 bg-transparent text-app-text hover:bg-card dark:border-neutral-700 dark:hover:bg-white/10',
    ];
@endphp

<label for="{{ $for }}" aria-hidden="true" data-file-button
    {{ $attributes->class([
        'inline-flex h-11 cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-control px-5 text-sm font-semibold select-none',
        'transition-[color,background-color,border-color,transform,filter] duration-[280ms] ease-brand active:scale-[0.98]',
        'peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand',
        $variants[$variant] ?? $variants['primary'],
    ]) }}>
    @if ($icon)
        <x-ui.icon :name="$icon" :size="18" />
    @endif
    {{ $label }}
</label>
@if ($chosen)
    <span class="block max-w-full truncate text-xs text-app-text-2" data-file-chosen data-empty="{{ $empty }}" aria-live="polite">{{ $empty }}</span>
@endif
