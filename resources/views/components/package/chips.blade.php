@props(['package'])

{{-- Phase 10D: the length and people chips of a package, the same on every card. --}}
<ul {{ $attributes->class('flex flex-wrap gap-1.5 text-xs font-semibold text-app-text-2') }}>
    <li class="inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2.5 py-1 dark:bg-white/10"><x-ui.icon name="schedule" :size="14" class="text-brand" />{{ $package->duration_minutes }} mins</li>
    <li class="inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2.5 py-1 dark:bg-white/10"><x-ui.icon name="group" :size="14" class="text-brand" />{{ $package->fitsText() }}</li>
    @if ($package->digital_outputs)
        <li class="inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2.5 py-1 dark:bg-white/10"><x-ui.icon name="photo_library" :size="14" class="text-brand" />{{ $package->digital_outputs }} edited {{ $package->digital_outputs === 1 ? 'photo' : 'photos' }}</li>
    @endif
</ul>
