@extends('layouts.public')

@section('title', 'Photography packages | '.config('app.name'))

@php
    $query = $filters->toQuery();
    $total = $categories->sum();
    $chipLink = fn (?string $name) => route('packages.index', array_filter(['category' => $name] + Arr::except($query, 'category')));
    $sortLabels = ['featured' => 'Featured first', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'duration' => 'Shortest session', 'name' => 'Name A–Z'];
@endphp

<x-seo
    :title="'Photography packages | '.config('app.name')"
    :description="'Browse the photography packages of '.config('app.name').': session length, how many people they fit, what is included and the price. Compare up to three packages side by side.'"
    :noindex="$filters->isActive() || $filters->sort !== 'featured' || $packages->currentPage() > 1" />

@section('main')
    <section class="mx-auto max-w-[1280px] px-4 pb-16 pt-8 sm:px-6 sm:pt-12">
        <nav aria-label="Breadcrumb" class="mb-6 text-xs font-semibold uppercase tracking-wider text-app-text-2">
            <ol class="flex flex-wrap items-center gap-1.5">
                <li><a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Home</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="text-brand">Photography packages</li>
            </ol>
        </nav>

        <header class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <div class="max-w-2xl">
                <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-brand"><span class="size-2 rounded-full bg-brand" aria-hidden="true"></span>Photography</p>
                <h1 class="mt-2 font-display text-3xl font-semibold leading-tight sm:text-5xl">Photography packages</h1>
                <p class="mt-3 text-app-text-2">Pick the session that fits your occasion. Tick up to three packages to compare them side by side, or let the finder suggest where to start.</p>
                <x-ui.button :href="route('packages.finder')" variant="outline" class="mt-4">
                    <x-ui.icon name="auto_awesome" :size="20" /> Help me choose
                </x-ui.button>
            </div>
            @if ($total > 0)
                <div class="flex items-center gap-4 self-start rounded-card border border-app-border bg-app-card p-4 shadow-[0_2px_8px_-2px_rgba(23,23,23,0.04)] md:self-end">
                    <span class="grid size-10 shrink-0 place-items-center rounded-control bg-card text-brand dark:bg-white/10" aria-hidden="true"><x-ui.icon name="compare_arrows" :size="22" /></span>
                    <p class="text-sm"><span class="block font-semibold">Compare packages</span><span class="text-app-text-2"><span class="tabular-nums">{{ $total }}</span> {{ Str::plural('package', $total) }} to choose from</span></p>
                </div>
            @endif
        </header>

        @if ($total === 0)
            <x-ui.card class="mt-8">
                <x-ui.empty-state icon="photo_camera" title="No packages are listed yet" description="Please check back soon, or contact the studio to ask about a session.">
                    <x-ui.button :href="route('home')" variant="outline">Back to home</x-ui.button>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <div class="mt-8 rounded-card border border-app-border bg-app-card p-3 sm:p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                <nav aria-label="Package categories">
                    <ul class="flex flex-wrap gap-2">
                        <li>
                            <a href="{{ $chipLink(null) }}" @if ($filters->category === null) aria-current="true" @endif
                                @class([
                                    'inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold focus-ring transition-colors duration-150 sm:min-h-9',
                                    'bg-ink text-white dark:bg-white dark:text-ink' => $filters->category === null,
                                    'border border-app-border bg-app-bg text-app-text-2 hover:text-app-text' => $filters->category !== null,
                                ])>All packages <span class="ml-1.5 tabular-nums opacity-70">{{ $total }}</span></a>
                        </li>
                        @foreach ($categories as $name => $count)
                            <li>
                                <a href="{{ $chipLink($name) }}" @if ($filters->category === $name) aria-current="true" @endif
                                    @class([
                                        'inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold focus-ring transition-colors duration-150 sm:min-h-9',
                                        'bg-ink text-white dark:bg-white dark:text-ink' => $filters->category === $name,
                                        'border border-app-border bg-app-bg text-app-text-2 hover:text-app-text' => $filters->category !== $name,
                                    ])>{{ $name }} <span class="ml-1.5 tabular-nums opacity-70">{{ $count }}</span></a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
                <p class="flex items-center gap-2 rounded-full bg-neutral-100 px-4 py-2 text-xs text-app-text-2 dark:bg-white/5"><x-ui.icon name="info" :size="16" class="shrink-0 text-brand" />Tick Compare on up to 3 packages to see them side by side.</p>
                </div>

                <form method="GET" action="{{ route('packages.index') }}" class="mt-4 border-t border-app-border pt-4">
                    @if ($filters->category !== null)
                        <input type="hidden" name="category" value="{{ $filters->category }}">
                    @endif
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <x-ui.input label="Number of people" name="people" type="number" min="1" max="500" step="1" inputmode="numeric" :value="$filters->people" />
                        <x-ui.input label="Longest session (minutes)" name="minutes" type="number" min="1" max="1440" step="1" inputmode="numeric" :value="$filters->minutes" />
                        <x-ui.input label="Budget (₱)" name="budget" type="number" min="0" max="99999999" step="0.01" inputmode="decimal" :value="$query['budget'] ?? null" />
                        <x-ui.select label="Backdrop" name="theme">
                            <option value="">Any backdrop</option>
                            @foreach ($themes as $theme)
                                <option value="{{ $theme->id }}" @selected($filters->theme === $theme->id)>{{ $theme->name }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.select label="Sort by" name="sort">
                            @foreach ($sortLabels as $value => $label)
                                <option value="{{ $value }}" @selected($filters->sort === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                    <p class="mt-2 text-xs text-app-text-2">With a budget set, packages whose price is only given on request are left out.</p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <x-ui.button type="submit">Apply</x-ui.button>
                        @if ($filters->isActive() || $filters->sort !== 'featured')
                            <x-ui.button :href="route('packages.index')" variant="ghost">Clear all</x-ui.button>
                        @endif
                    </div>
                </form>
            </div>

            <p class="mt-6 text-sm text-app-text-2" role="status">
                @if ($packages->total() === 0)
                    No packages match your choices.
                @else
                    Showing {{ $packages->firstItem() }}–{{ $packages->lastItem() }} of {{ $packages->total() }} {{ Str::plural('package', $packages->total()) }}
                @endif
            </p>

            @if ($packages->isEmpty())
                <x-ui.card class="mt-4">
                    <x-ui.empty-state icon="search_off" title="No package fits those choices" description="Try a bigger budget, fewer people or a longer session, or clear the filters.">
                        <x-ui.button :href="route('packages.index')" variant="outline">Show all packages</x-ui.button>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <form id="compare-form" method="GET" action="{{ route('packages.compare') }}"
                    x-data="packageCompare(3)" x-on:change="count()" x-on:pageshow.window="count()">
                    <ul class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($packages as $package)
                            @php $cover = $package->coverImage; @endphp
                            <li class="flex">
                                <x-ui.card hover design class="flex w-full flex-col">
                                    <x-slot:image>
                                        <div class="relative aspect-[16/10] bg-card">
                                            <a href="{{ route('packages.show', $package->slug) }}" tabindex="-1" aria-hidden="true" class="block size-full">
                                                @if ($cover)
                                                    <img src="{{ $cover->url() }}" alt="" width="800" height="500" loading="{{ $loop->index < 3 ? 'eager' : 'lazy' }}" decoding="async" class="size-full object-cover transition-transform duration-500 ease-out group-hover:scale-105">
                                                @else
                                                    <x-ui.image-placeholder class="size-full" />
                                                @endif
                                            </a>
                                            <span class="pointer-events-none absolute left-3 top-3 max-w-[55%] truncate rounded bg-black/65 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-white backdrop-blur-sm">{{ $package->category }}</span>
                                            <label class="absolute right-3 top-3 inline-flex min-h-11 cursor-pointer select-none items-center gap-2 rounded-full bg-white/95 px-3 text-[11px] font-bold uppercase tracking-wide text-ink shadow-sm backdrop-blur-md has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand sm:min-h-8 dark:bg-neutral-900/90 dark:text-white">
                                                <input type="checkbox" name="packages[]" value="{{ $package->slug }}" data-name="{{ $package->name }}" data-cover="{{ $cover?->url() }}"
                                                    x-bind:disabled="picked >= max && !$el.checked"
                                                    class="size-4 rounded accent-brand">
                                                Compare<span class="sr-only"> {{ $package->name }}</span>
                                            </label>
                                            <div class="pointer-events-none absolute inset-x-3 bottom-3 flex items-center justify-between gap-2 font-mono text-[11px] font-medium text-white [text-shadow:0_1px_3px_rgba(0,0,0,0.6)]">
                                                <span class="inline-flex items-center gap-1"><x-ui.icon name="schedule" :size="14" />{{ $package->duration_minutes }} mins</span>
                                                <span class="inline-flex items-center gap-1"><x-ui.icon name="group" :size="14" />Up to {{ $package->max_people }} {{ $package->max_people === 1 ? 'person' : 'people' }}</span>
                                            </div>
                                        </div>
                                    </x-slot:image>

                                    <h2 class="font-display text-xl font-semibold leading-snug">
                                        <a href="{{ route('packages.show', $package->slug) }}" class="inline-flex min-h-11 items-center rounded transition-colors hover:text-brand focus-ring">{{ $package->name }}</a>
                                    </h2>

                                    <p class="mt-1 text-sm leading-relaxed text-app-text-2">{{ $package->short_description }}</p>

                                    @if (! empty($package->inclusions))
                                        <div class="mt-4 rounded-control bg-neutral-100 p-3 dark:bg-white/5">
                                            <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Inclusions</p>
                                            <ul class="mt-2 space-y-1.5 text-sm">
                                                @foreach (array_slice($package->inclusions, 0, 3) as $item)
                                                    <li class="flex gap-2"><x-ui.icon name="check_circle" :size="18" class="mt-0.5 shrink-0 text-status-green" /><span>{{ $item }}</span></li>
                                                @endforeach
                                            </ul>
                                            @if (count($package->inclusions) > 3)
                                                <p class="mt-2 text-xs text-app-text-2">and {{ count($package->inclusions) - 3 }} more on the details page</p>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="mt-auto pt-5">
                                        <div class="rounded-control bg-neutral-100 px-3 py-3 dark:bg-white/5">
                                            <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Estimated price</p>
                                            <p class="mt-0.5 font-display text-lg font-bold tabular-nums">{{ $package->priceText() }}</p>
                                        </div>
                                        <div class="mt-3 grid grid-cols-2 gap-2">
                                            <x-ui.button :href="route('packages.show', $package->slug)" variant="outline" class="w-full">Details</x-ui.button>
                                            <x-package.book-button :package="$package" class="w-full" />
                                        </div>
                                    </div>
                                </x-ui.card>
                            </li>
                        @endforeach
                    </ul>

                    {{-- Fixed at the bottom as in the design. Without scripts it simply stays visible; Alpine slides it away while nothing is ticked. --}}
                    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-40 px-4 sm:px-6"
                        data-alpine-pending x-bind:data-alpine-pending="false"
                        x-bind:class="[ready ? 'transition-[opacity,translate] duration-300 ease-out' : '', picked === 0 ? 'translate-y-8 opacity-0' : '']"
                        x-bind:inert="picked === 0" role="region" aria-label="Compare packages">
                        <div class="pointer-events-auto mx-auto flex max-w-[1280px] flex-col gap-2 rounded-2xl bg-ink p-3 sm:gap-3 sm:p-4 text-white shadow-xl backdrop-blur-md md:flex-row md:items-center md:justify-between dark:border dark:border-white/10 dark:bg-neutral-900">
                            <div class="flex items-center gap-3">
                                <span class="hidden size-10 shrink-0 place-items-center rounded-xl bg-brand text-white sm:grid" aria-hidden="true"><x-ui.icon name="compare" :size="22" /></span>
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold" aria-live="polite">
                                        <span x-show="picked === 0">Tick Compare on 2 or 3 packages, then press Compare packages.</span>
                                        <span x-show="picked === 1" x-cloak>1 package selected. Tick one or two more.</span>
                                        <span x-show="picked >= 2" x-cloak><span x-text="picked"></span> packages selected to compare</span>
                                        <span x-show="picked > 0" x-cloak class="rounded-full bg-white/20 px-2 py-0.5 font-mono text-[11px] uppercase" x-text="picked + ' of ' + max + ' max'"></span>
                                    </p>
                                    <p x-show="picked > 0" x-cloak class="mt-0.5 truncate text-sm text-white/70" x-text="names.join(', ')"></p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-2 md:justify-end md:gap-3">
                                <ul x-show="covers.length > 0" x-cloak class="hidden -space-x-2 sm:flex" aria-hidden="true">
                                    <template x-for="url in covers" x-bind:key="url">
                                        <li class="size-9 overflow-hidden rounded-full bg-card ring-2 ring-ink dark:ring-neutral-900"><img x-bind:src="url" alt="" width="36" height="36" class="size-full object-cover"></li>
                                    </template>
                                </ul>
                                <button type="button" x-show="picked > 0" x-cloak x-on:click="clear()"
                                    class="inline-flex h-11 items-center rounded-control px-3 text-sm font-semibold text-white/80 transition-colors hover:text-white focus-ring">Clear selection</button>
                                <x-ui.button type="submit" x-bind:disabled="picked < 2" class="disabled:pointer-events-none disabled:opacity-60">Compare<span class="hidden sm:inline"> packages</span><x-ui.icon name="table_chart" :size="18" /></x-ui.button>
                            </div>
                        </div>
                    </div>
                </form>

                @if ($packages->hasPages())
                    <div class="mt-8">{{ $packages->links('pagination.simple') }}</div>
                @endif
            @endif

            <x-ui.notice variant="payment" class="mt-12">
                <p class="text-app-text-2">Choosing a package does not book it. The studio confirms your schedule and the final price after reviewing your request.</p>
            </x-ui.notice>
        @endif
    </section>
@endsection
