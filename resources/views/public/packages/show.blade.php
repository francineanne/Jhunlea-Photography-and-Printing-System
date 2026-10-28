@extends('layouts.public')

@section('title', $package->name.' | '.config('app.name'))

@php
    $images = $package->images->sortByDesc("is_cover")->values();
    $cover = $images->firstWhere('is_cover', true) ?? $images->first();
    $slides = $images->map(fn ($image, $index) => [
        'url' => $image->url(),
        'alt' => $image->alt_text ?: 'Photo '.($index + 1).' of '.$package->name,
    ])->all();
    $people = $package->max_people === 1 ? 'person' : 'people';
@endphp

<x-seo
    :title="$package->name.' | '.config('app.name')"
    :description="\Illuminate\Support\Str::limit($package->short_description, 155)"
    :image="$cover ? url($cover->url()) : null" />

@section('main')
    <article class="mx-auto max-w-[1280px] px-4 pb-16 pt-8 sm:px-6 sm:pt-12">
        <nav aria-label="Breadcrumb" class="mb-6 text-xs font-semibold uppercase tracking-wider text-app-text-2">
            <ol class="flex flex-wrap items-center gap-1.5">
                <li><a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Home</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('packages.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Photography packages</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="text-brand">{{ $package->name }}</li>
            </ol>
        </nav>

        <div class="grid gap-8 lg:grid-cols-12 lg:gap-12">
            {{-- Gallery: the first photo is plain HTML, so the page works without scripts. Thumbnails and arrows appear once Alpine is ready. --}}
            <section class="lg:col-span-7" aria-label="Photos of {{ $package->name }}"
                @if (count($slides) > 1) x-data="packageGallery(@js($slides))"
                    x-on:keydown.arrow-left="go(i - 1)" x-on:keydown.arrow-right="go(i + 1)" @endif>
                <div class="group relative aspect-[4/3] overflow-hidden rounded-card border border-app-border bg-card">
                    @if ($images->isEmpty())
                        <x-ui.image-placeholder class="size-full" />
                    @else
                        @foreach ($images as $index => $image)
                            <img src="{{ $image->url() }}" alt="{{ $slides[$index]['alt'] }}" width="1200" height="900"
                                loading="eager" decoding="async"
                                @if ($index > 0) hidden @endif
                                @if (count($slides) > 1) x-bind:hidden="i !== {{ $index }}" x-bind:class="dim ? 'opacity-70' : ''" @endif
                                class="size-full object-cover transition-[opacity,scale] duration-500 group-hover:scale-[1.02]">
                        @endforeach
                    @endif

                    @if (count($slides) > 1)
                        <button type="button" x-cloak x-on:click="go(i - 1)" aria-label="Previous photo"
                            class="absolute left-3 top-1/2 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink shadow focus-ring"><x-ui.icon name="chevron_left" /></button>
                        <button type="button" x-cloak x-on:click="go(i + 1)" aria-label="Next photo"
                            class="absolute right-3 top-1/2 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink shadow focus-ring"><x-ui.icon name="chevron_right" /></button>
                        <p x-cloak class="absolute bottom-3 right-3 rounded-full bg-black/60 px-3 py-1 text-xs font-semibold text-white tabular-nums" aria-live="polite">
                            Photo <span x-text="i + 1"></span> of <span x-text="slides.length"></span>
                        </p>
                    @endif
                </div>

                @if (count($slides) > 1)
                    <ul x-cloak class="mt-3 grid grid-cols-4 gap-2 sm:grid-cols-6" aria-label="Choose a photo">
                        @foreach ($images as $index => $image)
                            <li>
                                <button type="button" x-on:click="show({{ $index }})" x-bind:aria-pressed="(i === {{ $index }}).toString()"
                                    aria-label="Show photo {{ $index + 1 }} of {{ $images->count() }}"
                                    class="block aspect-[4/3] w-full overflow-hidden rounded-control opacity-75 transition-all hover:opacity-100 focus-ring"
                                    x-bind:class="i === {{ $index }} ? 'scale-[1.02] !opacity-100 ring-2 ring-brand' : ''">
                                    <img src="{{ $image->url() }}" alt="" width="200" height="150" loading="lazy" decoding="async" class="size-full object-cover">
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <div class="lg:col-span-5">
                <div class="lg:sticky lg:top-28">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded bg-neutral-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-app-text-2 dark:bg-white/10">{{ $package->category }}</span>
                        @if ($package->is_featured)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand/10 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-brand"><span class="size-2 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Featured package</span>
                        @endif
                    </div>
                    <h1 class="mt-3 font-display text-3xl font-semibold leading-tight sm:text-4xl">{{ $package->name }}</h1>
                    <p class="mt-3 text-app-text-2">{{ $package->short_description }}</p>

                    <div class="mt-6 rounded-card border border-app-border bg-app-card p-5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Estimated price</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums">{{ $package->priceText() }}</p>
                        <p class="mt-2 text-sm text-app-text-2">This is an estimate. The studio confirms the final price after reviewing your request.</p>
                    </div>

                    <x-ui.notice variant="payment" class="mt-4" />

                    <dl class="mt-6 grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-control bg-neutral-100 p-3 dark:bg-white/5">
                            <dt class="flex items-center gap-1.5 text-app-text-2"><x-ui.icon name="schedule" :size="16" />Session length</dt>
                            <dd class="mt-1 font-semibold tabular-nums">{{ $package->duration_minutes }} mins</dd>
                        </div>
                        <div class="rounded-control bg-neutral-100 p-3 dark:bg-white/5">
                            <dt class="flex items-center gap-1.5 text-app-text-2"><x-ui.icon name="group" :size="16" />Fits</dt>
                            <dd class="mt-1 font-semibold">Up to {{ $package->max_people }} {{ $people }}</dd>
                        </div>
                        @if ($package->digital_outputs)
                            <div class="col-span-2 rounded-control bg-neutral-100 p-3 dark:bg-white/5">
                                <dt class="flex items-center gap-1.5 text-app-text-2"><x-ui.icon name="photo_library" :size="16" />Digital photos</dt>
                                <dd class="mt-1 font-semibold tabular-nums">{{ $package->digital_outputs }} edited {{ $package->digital_outputs === 1 ? 'photo' : 'photos' }}</dd>
                            </div>
                        @endif
                    </dl>

                    <div class="mt-6 flex flex-wrap gap-2">
                        <x-package.book-button :package="$package" label="Book this package" class="w-full sm:w-auto" />
                        <x-ui.button :href="route('packages.index')" variant="outline"><x-ui.icon name="arrow_back" :size="20" />All packages</x-ui.button>
                        <x-ui.button :href="route('packages.finder')" variant="ghost"><x-ui.icon name="auto_awesome" :size="20" />Help me choose</x-ui.button>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-12 grid gap-10 lg:grid-cols-12 lg:gap-12">
            <div class="space-y-10 lg:col-span-7">
                @if ($package->description)
                    <section aria-labelledby="about-heading">
                        <h2 id="about-heading" class="font-display text-2xl font-semibold">About this package</h2>
                        <p class="mt-3 whitespace-pre-line leading-relaxed text-app-text-2">{{ $package->description }}</p>
                    </section>
                @endif

                @if (! empty($package->inclusions))
                    <section aria-labelledby="includes-heading">
                        <h2 id="includes-heading" class="font-display text-2xl font-semibold">What is included</h2>
                        <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach ($package->inclusions as $item)
                                <li class="flex gap-2.5 rounded-control border border-app-border bg-app-card p-3 text-sm">
                                    <x-ui.icon name="check_circle" :size="20" class="mt-0.5 shrink-0 text-status-green" /><span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($package->terms)
                    <section aria-labelledby="terms-heading">
                        <h2 id="terms-heading" class="font-display text-2xl font-semibold">Package terms</h2>
                        <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-app-text-2">{{ $package->terms }}</p>
                    </section>
                @endif
            </div>

            @if ($package->themes->isNotEmpty())
                <section class="lg:col-span-5" aria-labelledby="themes-heading">
                    <h2 id="themes-heading" class="font-display text-2xl font-semibold">Backdrop themes</h2>
                    <p class="mt-1 text-sm text-app-text-2">Backdrops you can choose with this package.</p>
                    <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                        @foreach ($package->themes as $theme)
                            <li class="overflow-hidden rounded-card border border-app-border bg-app-card">
                                <div class="relative aspect-[16/9] bg-card">
                                    @if ($theme->imageUrl())
                                        <img src="{{ $theme->imageUrl() }}" alt="" width="640" height="360" loading="lazy" decoding="async" class="size-full object-cover">
                                    @else
                                        <x-ui.image-placeholder icon="palette" class="size-full" />
                                    @endif
                                </div>
                                <div class="p-3">
                                    <h3 class="text-sm font-semibold">{{ $theme->name }}</h3>
                                    @if ($theme->description)
                                        <p class="mt-0.5 text-xs text-app-text-2">{{ $theme->description }}</p>
                                    @endif
                                    @if (! empty($theme->color_swatches))
                                        <ul class="mt-2 flex gap-1.5" aria-label="Colors of {{ $theme->name }}">
                                            @foreach ($theme->color_swatches as $color)
                                                <li class="size-6 rounded-full ring-1 ring-black/10 dark:ring-white/20" style="background-color: {{ $color }}" title="{{ $color }}"><span class="sr-only">{{ $color }}</span></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        @if ($related->isNotEmpty())
            <section class="mt-14" aria-labelledby="related-heading">
                <h2 id="related-heading" class="font-display text-2xl font-semibold">More {{ $package->category }} packages</h2>
                <ul class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($related as $other)
                        <li class="flex">
                            <x-ui.card hover design class="flex w-full flex-col">
                                <x-slot:image>
                                    <a href="{{ route('packages.show', $other->slug) }}" tabindex="-1" aria-hidden="true" class="block aspect-[4/3] bg-card">
                                        @if ($other->coverImage)
                                            <img src="{{ $other->coverImage->url() }}" alt="" width="800" height="600" loading="lazy" decoding="async" class="size-full object-cover transition-transform duration-500 ease-out group-hover:scale-105">
                                        @else
                                            <x-ui.image-placeholder class="size-full" />
                                        @endif
                                    </a>
                                </x-slot:image>
                                <h3 class="font-display text-lg font-semibold leading-snug"><a href="{{ route('packages.show', $other->slug) }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">{{ $other->name }}</a></h3>
                                <p class="mt-1 text-sm text-app-text-2">{{ $other->duration_minutes }} mins · up to {{ $other->max_people }} {{ $other->max_people === 1 ? 'person' : 'people' }}</p>
                                <p class="mt-auto pt-3 text-sm font-bold tabular-nums">{{ $other->priceText() }}</p>
                            </x-ui.card>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </article>
@endsection
