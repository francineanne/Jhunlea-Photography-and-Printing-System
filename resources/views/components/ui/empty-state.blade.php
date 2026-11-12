@props(['icon' => 'inbox', 'title', 'description' => null, 'level' => 3])

{{-- level: the heading level that follows the page outline (9C); 3 under a section heading, 2 right under the h1 --}}
@php($level = in_array((int) $level, [2, 3, 4], true) ? (int) $level : 3)

<div {{ $attributes->class('flex flex-col items-center px-4 py-10 text-center sm:py-14') }}>
    <span class="grid size-14 place-items-center rounded-full bg-card text-app-text-2 dark:bg-white/5">
        <x-ui.icon :name="$icon" :size="28" />
    </span>
    <h{{ $level }} class="mt-4 font-display text-xl font-semibold">{{ $title }}</h{{ $level }}>
    @if ($description)
        <p class="mt-1.5 max-w-sm text-sm text-app-text-2">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
