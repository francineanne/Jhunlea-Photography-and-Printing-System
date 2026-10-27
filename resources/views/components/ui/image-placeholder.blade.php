@props(['icon' => 'photo_camera'])

{{-- Shown where a package or theme has no photo yet. Decorative only; it never pretends to be a real photo. --}}
<div {{ $attributes->class('relative grid place-items-center overflow-hidden bg-gradient-to-br from-neutral-100 via-neutral-50 to-red-50 text-neutral-300 dark:from-white/5 dark:via-white/[0.03] dark:to-brand/10 dark:text-neutral-600') }} aria-hidden="true">
    <span class="pointer-events-none absolute size-2/3 rounded-full bg-brand/5 blur-2xl"></span>
    <x-ui.icon :name="$icon" :size="40" class="relative" />
</div>
