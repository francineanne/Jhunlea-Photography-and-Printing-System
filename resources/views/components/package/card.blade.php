@props(['package', 'compare' => false, 'inclusions' => false, 'eager' => false, 'level' => 3])

{{--
    Phase 10D: the ONE package card (landing, packages list, finder, "you may also like"): 4:3 photo or the designed
    placeholder, category, a title clamped to two lines, a short description clamped to three, the same chips, the one
    price component and the buttons pinned to the bottom, so the cards of a row are equal in height.
    compare: adds the "Compare" tick of the packages list (inside #compare-form). inclusions: the first three.
    The default slot replaces the two buttons (Details, Book).
--}}
@php($url = route('packages.show', $package->slug))
<x-ui.card hover design {{ $attributes->class('flex w-full flex-col') }}>
    <x-slot:image>
        <x-package.media :package="$package" :eager="$eager">
            <a href="{{ $url }}" tabindex="-1" aria-hidden="true" class="absolute inset-0"></a>
            <span class="pointer-events-none absolute left-3 top-3 max-w-[55%] truncate rounded bg-black/65 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-white backdrop-blur-sm">{{ $package->category }}</span>
            @if ($compare)
                <label class="absolute right-3 top-3 inline-flex min-h-11 cursor-pointer select-none items-center gap-2 rounded-full bg-white/95 px-3 text-[11px] font-bold uppercase tracking-wide text-ink shadow-sm backdrop-blur-md has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand sm:min-h-8 dark:bg-neutral-900/90 dark:text-white">
                    <input type="checkbox" name="packages[]" value="{{ $package->slug }}" data-name="{{ $package->name }}" data-cover="{{ $package->coverImage?->url() }}"
                        x-bind:disabled="picked >= max && !$el.checked"
                        class="size-4 rounded accent-brand">
                    Compare<span class="sr-only"> {{ $package->name }}</span>
                </label>
            @endif
        </x-package.media>
    </x-slot:image>

    <h{{ $level }} class="font-display text-xl font-semibold leading-snug">
        <a href="{{ $url }}" title="{{ $package->name }}" class="line-clamp-2 min-h-11 rounded py-1.5 transition-colors hover:text-brand focus-ring">{{ $package->name }}</a>
    </h{{ $level }}>
    <x-package.chips :package="$package" class="mt-2" />

    @if (filled($package->short_description))
        <p class="mt-3 line-clamp-3 text-sm leading-relaxed text-app-text-2">{{ $package->short_description }}</p>
    @endif

    @if ($inclusions && ! empty($package->inclusions))
        <div class="mt-4 rounded-control bg-neutral-100 p-3 dark:bg-white/5">
            <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Inclusions</p>
            <ul class="mt-2 space-y-1.5 text-sm">
                @foreach (array_slice($package->inclusions, 0, 3) as $item)
                    <li class="flex gap-2"><x-ui.icon name="check_circle" :size="18" class="mt-0.5 shrink-0 text-status-green" /><span class="min-w-0 break-words">{{ $item }}</span></li>
                @endforeach
            </ul>
            @if (count($package->inclusions) > 3)
                <p class="mt-2 text-xs text-app-text-2">and {{ count($package->inclusions) - 3 }} more on the details page</p>
            @endif
        </div>
    @endif

    <div class="mt-auto pt-5">
        <x-ui.price :text="$package->priceText()" label="Estimated price" class="border-t border-app-border pt-4" />
        <div class="mt-3 grid grid-cols-2 gap-2">
            @if ($slot->isNotEmpty())
                {{ $slot }}
            @else
                <x-ui.button :href="$url" variant="outline" class="w-full">Details<span class="sr-only"> of {{ $package->name }}</span></x-ui.button>
                <x-package.book-button :package="$package" class="w-full" />
            @endif
        </div>
    </div>
</x-ui.card>
