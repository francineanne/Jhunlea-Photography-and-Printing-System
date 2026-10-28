@extends('layouts.public')

@section('title', config('app.name'))

@php
    $heroCover = $featured->map->coverImage->filter()->first();
    $servicesTotal = $serviceCategories->sum();
@endphp

<x-seo :title="config('app.name')"
    description="Photography packages and printing services in one studio. Browse what we offer, compare packages and see prices. Payment is made face-to-face."
    :image="$heroCover ? url($heroCover->url()) : null" />

@section('main')
    {{-- Hero --}}
    <section class="mx-auto grid max-w-[1280px] items-center gap-10 px-4 pb-14 pt-10 sm:px-6 sm:pt-16 lg:grid-cols-2 lg:gap-14 lg:pb-20">
        <div>
            <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-brand"><span class="h-0.5 w-6 bg-brand" aria-hidden="true"></span>Photo studio &amp; print services</p>
            <h1 class="mt-3 font-display text-4xl font-semibold leading-[1.1] sm:text-6xl">Capture it.<br><span class="font-normal italic text-brand">Print it.</span> Keep it.</h1>
            <p class="mt-5 max-w-lg text-lg leading-relaxed text-app-text-2">Photography sessions and printing under one roof. See what we offer, what it costs and how long it takes before you send a request.</p>
            <div class="mt-7 flex flex-wrap gap-3">
                <x-ui.button :href="route('packages.index')">Browse packages<x-ui.icon name="arrow_forward" :size="20" /></x-ui.button>
                <x-ui.button :href="route('services.index')" variant="outline">See printing services</x-ui.button>
            </div>
            <x-ui.notice variant="payment" class="mt-8 max-w-lg" />
        </div>

        <div class="relative">
            <div class="group aspect-[4/5] overflow-hidden rounded-card border border-app-border bg-card shadow-[0_12px_32px_-8px_rgba(23,23,23,0.12)] sm:aspect-[5/4] lg:aspect-[4/5]">
                @if ($heroCover)
                    <img src="{{ $heroCover->url() }}" alt="" width="960" height="1200" loading="eager" fetchpriority="high" decoding="async" class="size-full object-cover transition-transform duration-700 ease-out group-hover:scale-105">
                @else
                    <x-ui.image-placeholder class="size-full" />
                @endif
            </div>
            @if ($featured->isNotEmpty())
                <a href="{{ route('packages.show', $featured->first()->slug) }}" class="absolute bottom-4 left-4 right-4 rounded-card border border-white/40 bg-white/85 p-4 text-ink shadow-lg backdrop-blur-md focus-ring sm:right-auto sm:max-w-xs dark:bg-neutral-900/85 dark:text-white">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-brand">Featured package</span>
                    <span class="mt-0.5 block font-display text-lg font-semibold leading-snug">{{ $featured->first()->name }}</span>
                    <span class="mt-0.5 block text-sm tabular-nums opacity-80">{{ $featured->first()->priceText() }}</span>
                </a>
            @endif
        </div>
    </section>

    {{-- What the studio offers: counts and the starting price come from the catalog --}}
    <section class="border-y border-app-border bg-neutral-50 py-14 dark:bg-white/[0.02] sm:py-20" aria-labelledby="offer-heading">
        <div class="mx-auto max-w-[1280px] px-4 sm:px-6">
            <h2 id="offer-heading" class="font-display text-3xl font-semibold sm:text-4xl">Everything in one studio</h2>
            <p class="mt-2 max-w-xl text-app-text-2">From portraits and family sessions to documents and photo prints.</p>

            <div class="mt-8 grid gap-5 md:grid-cols-3">
                <x-ui.card hover design class="flex flex-col">
                    <span class="grid size-12 place-items-center rounded-control bg-brand/10 text-brand" aria-hidden="true"><x-ui.icon name="photo_camera" :size="26" /></span>
                    <h3 class="mt-4 font-display text-xl font-semibold">Photography packages</h3>
                    @if ($packageStats->total > 0)
                        <p class="mt-2 text-sm text-app-text-2">{{ $packageStats->total }} {{ Str::plural('package', $packageStats->total) }} to choose from.
                            @if ($packageStats->lowest_price !== null)
                                Starting at <span class="font-semibold text-app-text tabular-nums">{{ \App\Support\Money::format($packageStats->lowest_price) }}</span>.
                            @endif
                        </p>
                    @else
                        <p class="mt-2 text-sm text-app-text-2">Packages will be listed here soon.</p>
                    @endif
                    <div class="mt-auto pt-5"><x-ui.button :href="route('packages.index')" variant="outline" size="sm">View packages</x-ui.button></div>
                </x-ui.card>

                <x-ui.card hover design class="flex flex-col">
                    <span class="grid size-12 place-items-center rounded-control bg-brand/10 text-brand" aria-hidden="true"><x-ui.icon name="print" :size="26" /></span>
                    <h3 class="mt-4 font-display text-xl font-semibold">Printing services</h3>
                    @if ($servicesTotal > 0)
                        <ul class="mt-3 flex flex-wrap gap-2">
                            @foreach ($serviceCategories as $name => $count)
                                <li><a href="{{ route('services.index', ['category' => $name]) }}" class="inline-flex min-h-11 items-center rounded-full border border-app-border bg-app-bg px-3.5 text-sm font-semibold text-app-text-2 hover:text-app-text focus-ring sm:min-h-9">{{ $name }} <span class="ml-1.5 tabular-nums opacity-70">{{ $count }}</span></a></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-2 text-sm text-app-text-2">Printing services will be listed here soon.</p>
                    @endif
                    <div class="mt-auto pt-5"><x-ui.button :href="route('services.index')" variant="outline" size="sm">View services</x-ui.button></div>
                </x-ui.card>

                <x-ui.card hover design class="flex flex-col">
                    <span class="grid size-12 place-items-center rounded-control bg-brand/10 text-brand" aria-hidden="true"><x-ui.icon name="auto_awesome" :size="26" /></span>
                    <h3 class="mt-4 font-display text-xl font-semibold">Package finder</h3>
                    <p class="mt-2 text-sm text-app-text-2">Not sure which package suits you? Answer a few questions and see the closest matches, with the reasons why.</p>
                    <div class="mt-auto pt-5"><x-ui.button :href="route('packages.finder')" variant="outline" size="sm">Help me choose</x-ui.button></div>
                </x-ui.card>
            </div>
        </div>
    </section>

    @if ($featured->isNotEmpty())
        <section class="mx-auto max-w-[1280px] px-4 py-14 sm:px-6 sm:py-20" aria-labelledby="featured-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="featured-heading" class="font-display text-3xl font-semibold sm:text-4xl">Featured packages</h2>
                    <p class="mt-2 text-app-text-2">Prices are estimates. The studio confirms the final price after review.</p>
                </div>
                <a href="{{ route('packages.index') }}" class="inline-flex min-h-11 items-center gap-1 rounded text-sm font-semibold text-brand focus-ring">All packages<x-ui.icon name="arrow_forward" :size="18" /></a>
            </div>

            <ul class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($featured as $package)
                    <li class="flex">
                        <x-ui.card hover design class="flex w-full flex-col">
                            <x-slot:image>
                                <a href="{{ route('packages.show', $package->slug) }}" tabindex="-1" aria-hidden="true" class="block aspect-[4/3] bg-card">
                                    @if ($package->coverImage)
                                        <img src="{{ $package->coverImage->url() }}" alt="" width="800" height="600" loading="lazy" decoding="async" class="size-full object-cover transition-transform duration-500 ease-out group-hover:scale-105">
                                    @else
                                        <x-ui.image-placeholder class="size-full" />
                                    @endif
                                </a>
                            </x-slot:image>
                            <span class="rounded bg-neutral-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-app-text-2 dark:bg-white/10">{{ $package->category }}</span>
                            <h3 class="mt-2 font-display text-xl font-semibold leading-snug"><a href="{{ route('packages.show', $package->slug) }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">{{ $package->name }}</a></h3>
                            <p class="mt-1 text-sm text-app-text-2">{{ $package->duration_minutes }} mins · up to {{ $package->max_people }} {{ $package->max_people === 1 ? 'person' : 'people' }}</p>
                            <p class="mt-3 text-sm leading-relaxed text-app-text-2">{{ $package->short_description }}</p>
                            <div class="mt-auto pt-5">
                                <div class="flex items-end justify-between gap-3 border-t border-app-border pt-4">
                                    <p class="font-bold tabular-nums">{{ $package->priceText() }}</p>
                                    <div class="flex shrink-0 gap-2">
                                        <x-ui.button :href="route('packages.show', $package->slug)" variant="outline" size="sm">Details</x-ui.button>
                                        <x-package.book-button :package="$package" size="sm" />
                                    </div>
                                </div>
                            </div>
                        </x-ui.card>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Closing band --}}
    <section class="bg-ink text-white dark:bg-neutral-900" aria-labelledby="cta-heading">
        <div class="mx-auto flex max-w-[1280px] flex-col gap-6 px-4 py-12 sm:px-6 md:flex-row md:items-center md:justify-between">
            <div class="max-w-xl">
                <h2 id="cta-heading" class="font-display text-3xl font-semibold">Ready to capture your milestones?</h2>
                <p class="mt-2 text-white/70">Look through the packages first. Nothing is booked or charged until the studio confirms it and you pay face-to-face.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button :href="route('packages.index')">Browse packages</x-ui.button>
                @guest
                    <x-ui.button :href="route('register')" variant="outline" class="border-white/30 text-white hover:bg-white/10">Create an account</x-ui.button>
                @endguest
            </div>
        </div>
    </section>
@endsection
