@props(['icon' => 'photo_camera', 'label' => null, 'category' => null])

{{--
    Shown where a package or theme has no photo yet. Decorative only; it never pretends to be a real photo.
    Phase 10D: never an empty box. A soft gradient with two quiet lens rings, the category's icon and, when given, the
    name (the real name is always written next to it as text, so this stays aria-hidden).
--}}
@php
    $icon = match (strtolower((string) $category)) {
        'portrait' => 'person',
        'couple' => 'favorite',
        'family' => 'family_restroom',
        'graduation' => 'school',
        'kids and birthday' => 'cake',
        'event' => 'celebration',
        default => $icon,
    };
@endphp
<div {{ $attributes->class('relative grid place-items-center overflow-hidden bg-gradient-to-br from-neutral-100 via-neutral-50 to-red-100/70 text-neutral-400 dark:from-neutral-800 dark:via-neutral-900 dark:to-brand/25 dark:text-neutral-400') }} aria-hidden="true" data-image-placeholder>
    <span class="pointer-events-none absolute aspect-square h-[130%] rounded-full border border-brand/10 dark:border-white/5"></span>
    <span class="pointer-events-none absolute aspect-square h-[85%] rounded-full border border-brand/10 bg-white/30 dark:border-white/5 dark:bg-white/[0.03]"></span>
    <span class="relative flex max-w-[80%] flex-col items-center gap-2 text-center">
        <span class="grid size-12 place-items-center rounded-full bg-white/80 text-brand shadow-sm dark:bg-white/10 dark:text-[#ffb4ac]"><x-ui.icon :name="$icon" :size="26" /></span>
        @if (filled($label))
            <span class="line-clamp-2 font-display text-sm font-semibold leading-snug text-neutral-600 dark:text-neutral-300">{{ $label }}</span>
        @endif
    </span>
</div>
