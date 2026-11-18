@extends('layouts.public')

@section('title', config('app.name'))

@php
    $heroCover = $featured->map->coverImage->filter()->first();
    $servicesTotal = $serviceCategories->sum();
@endphp

<x-seo :title="config('app.name')"
    description="Photography packages and printing services in one studio. Browse what we offer, compare packages and see prices. Pay at the shop or by GCash."
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
        </div>

        <div class="relative">
            <div class="group aspect-[4/5] overflow-hidden rounded-card border border-app-border bg-card shadow-[0_12px_32px_-8px_rgba(23,23,23,0.12)] sm:aspect-[5/4] lg:aspect-[4/5]">
                @if ($heroCover)
                    <img src="{{ $heroCover->url() }}" alt="" width="960" height="1200" loading="eager" fetchpriority="high" decoding="async" class="size-full object-cover transition-transform duration-700 ease-out group-hover:scale-105">
                @else
                    <x-ui.image-placeholder class="size-full" :category="$featured->first()?->category" />
                @endif
            </div>
            @if ($featured->isNotEmpty())
                <a href="{{ route('packages.show', $featured->first()->slug) }}" class="absolute bottom-4 left-4 right-4 rounded-card border border-white/40 bg-white/85 p-4 text-ink shadow-lg backdrop-blur-md focus-ring sm:right-auto sm:max-w-xs dark:bg-neutral-900/85 dark:text-white">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-brand">Featured package</span>
                    <span class="mt-0.5 block font-display text-lg font-semibold leading-snug">{{ $featured->first()->name }}</span>
                    <span class="mt-0.5 block text-sm font-semibold tabular-nums opacity-80">{{ explode(' — ', $featured->first()->priceText())[0] }}</span>
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
                    <span class="grid size-12 place-items-center rounded-control bg-brand/10 text-brand" aria-hidden="true"><x-ui.icon name="travel_explore" :size="26" /></span>
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
                        <x-package.card :package="$package" />
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Phase 10D: the first published questions; each is a native <details>, so it works without scripts --}}
    @if ($faqs->isNotEmpty())
        <section class="border-t border-app-border bg-neutral-50 py-14 dark:bg-white/[0.02] sm:py-20" aria-labelledby="faq-heading">
            <div class="mx-auto grid max-w-[1280px] gap-8 px-4 sm:px-6 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    <p class="text-xs font-bold uppercase tracking-widest text-brand">Help</p>
                    <h2 id="faq-heading" class="mt-2 font-display text-3xl font-semibold sm:text-4xl">Questions customers ask</h2>
                    <p class="mt-2 text-app-text-2">Quick answers about printing, photoshoots and paying.</p>
                    @if ($moreFaqs)
                        <x-ui.button :href="route('faq')" variant="outline" class="mt-5">See all questions<x-ui.icon name="arrow_forward" :size="18" /></x-ui.button>
                    @else
                        <a href="{{ route('faq') }}" class="mt-4 inline-flex min-h-11 items-center gap-1 rounded text-sm font-semibold text-brand focus-ring">Open the FAQ page<x-ui.icon name="arrow_forward" :size="18" /></a>
                    @endif
                </div>
                <div class="space-y-3 lg:col-span-8">
                    @foreach ($faqs as $faq)
                        <details class="ui-accordion group rounded-card border border-app-border bg-app-card shadow-sm open:border-brand/40 dark:shadow-none">
                            <summary class="flex min-h-14 cursor-pointer list-none items-start justify-between gap-4 rounded-card px-4 py-4 font-semibold focus-ring sm:px-5 [&::-webkit-details-marker]:hidden">
                                <span class="min-w-0 break-words">{{ $faq->question }}</span>
                                <x-ui.icon name="expand_more" :size="22" class="shrink-0 text-brand transition-transform duration-300 group-open:rotate-180" />
                            </summary>
                            <div class="whitespace-pre-line break-words border-t border-app-border px-4 py-4 text-app-text-2 sm:px-5">{{ $faq->answer }}</div>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Phase 10D: where the shop is and how to reach it. Only what the owner filled in on the Settings page --}}
    @php($visit = array_filter([$shop['address'] ?? null, $shop['phone'] ?? null, $shop['email'] ?? null, $shop['opening_hours'] ?? null, $shop['facebook_url'] ?? null]))
    @if ($visit !== [])
        <section class="mx-auto max-w-[1280px] px-4 py-14 sm:px-6 sm:py-20" aria-labelledby="visit-heading">
            <p class="text-xs font-bold uppercase tracking-widest text-brand">Find us</p>
            <h2 id="visit-heading" class="mt-2 font-display text-3xl font-semibold sm:text-4xl">Visit us</h2>
            <div @class(['mt-8 grid gap-5', 'lg:grid-cols-2' => $map !== null])>
                <x-ui.card class="flex flex-col">
                    {{-- Phase 10G: every line is the same <x-ui.contact-item>: the value sits right under its label --}}
                    <address class="space-y-3 not-italic">
                        @if (filled($shop['address'] ?? null))
                            <x-ui.contact-item icon="location_on" label="Address"><span class="whitespace-pre-line">{{ $shop['address'] }}</span></x-ui.contact-item>
                        @endif
                        @if (filled($shop['opening_hours'] ?? null))
                            <x-ui.contact-item icon="schedule" label="Shop opening hours" note="Photoshoot times are in the booking calendar.">{{ $shop['opening_hours'] }}</x-ui.contact-item>
                        @endif
                        @if (filled($shop['phone'] ?? null))
                            <x-ui.contact-item icon="call" label="Phone" :href="\App\Support\PhilippineMobile::telHref($shop['phone'])">{{ \App\Support\PhilippineMobile::display($shop['phone']) }}</x-ui.contact-item>
                        @endif
                        @if (filled($shop['email'] ?? null))
                            <x-ui.contact-item icon="mail" label="Email" :href="'mailto:'.$shop['email']"><span class="break-all">{{ $shop['email'] }}</span></x-ui.contact-item>
                        @endif
                        @if (filled($shop['facebook_url'] ?? null))
                            <x-ui.contact-item icon="thumb_up" label="Facebook" :href="$shop['facebook_url']" external>Our Facebook page</x-ui.contact-item>
                        @endif
                    </address>
                    @if ($map !== null)
                        <div class="mt-auto pt-5"><x-ui.button :href="$map['link']" variant="outline" target="_blank" rel="noopener noreferrer external"><x-ui.icon name="map" :size="18" />Open in Google Maps<span class="sr-only"> (opens in a new tab)</span></x-ui.button></div>
                    @endif
                </x-ui.card>
                @if ($map !== null)
                    {{-- Loaded only when it scrolls near the screen; the space is kept, so nothing shifts --}}
                    <div class="aspect-[4/3] overflow-hidden rounded-card border border-app-border bg-card lg:aspect-auto lg:min-h-[22rem]">
                        <iframe src="{{ $map['embed'] }}" title="Map showing where {{ $shop['shop_name'] ?: config('app.name') }} is" loading="lazy" referrerpolicy="no-referrer" class="size-full border-0" allowfullscreen></iframe>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- Closing band --}}
    <section class="bg-ink text-white dark:bg-neutral-900" aria-labelledby="cta-heading">
        <div class="mx-auto flex max-w-[1280px] flex-col gap-6 px-4 py-12 sm:px-6 md:flex-row md:items-center md:justify-between">
            <div class="max-w-xl">
                <h2 id="cta-heading" class="font-display text-3xl font-semibold">Ready to capture your milestones?</h2>
                <p class="mt-2 text-white/70">Look through the packages first. Nothing is booked or charged until the studio confirms it; you pay at the shop or by GCash.</p>
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
