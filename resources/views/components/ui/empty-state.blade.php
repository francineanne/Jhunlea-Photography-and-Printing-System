@props(['icon' => 'inbox', 'title', 'description' => null])

<div {{ $attributes->class('flex flex-col items-center px-4 py-10 text-center sm:py-14') }}>
    <span class="grid size-14 place-items-center rounded-full bg-card text-app-text-2 dark:bg-white/5">
        <x-ui.icon :name="$icon" :size="28" />
    </span>
    <h3 class="mt-4 font-display text-xl font-semibold">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1.5 max-w-sm text-sm text-app-text-2">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
