@extends('layouts.public')

@section('title', 'Package finder | '.config('app.name'))

<x-seo :title="'Package finder | '.config('app.name')"
    description="Answer a few questions and see which photography packages fit your occasion, group size and budget, with the reasons why."
    :noindex="$asked" />

@section('main')
    <section class="mx-auto max-w-[1280px] px-4 pb-16 pt-8 sm:px-6 sm:pt-12">
        <nav aria-label="Breadcrumb" class="mb-6 text-xs font-semibold uppercase tracking-wider text-app-text-2">
            <ol class="flex flex-wrap items-center gap-1.5">
                <li><a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Home</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('packages.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Photography packages</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="text-brand">Package finder</li>
            </ol>
        </nav>

        <header class="max-w-2xl">
            <p class="text-xs font-bold uppercase tracking-widest text-brand">Package finder</p>
            <h1 class="mt-2 font-display text-3xl font-semibold leading-tight sm:text-5xl">Which package fits you?</h1>
            <p class="mt-3 text-app-text-2">Answer any of these questions. You can skip the ones you are not sure about. The finder suggests packages from what the studio offers. It is a suggestion, not a booking or a price quote.</p>
        </header>

        @if ($categories->isEmpty())
            <x-ui.card class="mt-8">
                <x-ui.empty-state icon="photo_camera" title="No packages are listed yet" description="The finder needs packages to suggest from. Please check back soon.">
                    <x-ui.button :href="route('home')" variant="outline">Back to home</x-ui.button>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <form method="GET" action="{{ route('packages.finder') }}" class="mt-8 space-y-8">
                <fieldset>
                    <legend class="font-display text-xl font-semibold">1. What is the occasion?</legend>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-card border border-app-border bg-app-card p-4 text-sm font-semibold transition-[color,background-color,border-color,box-shadow] duration-200 hover:shadow-md has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand">
                            <input type="radio" name="category" value="" class="size-5 accent-brand" @checked($answers->category === null)>
                            No preference
                        </label>
                        @foreach ($categories as $name)
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-card border border-app-border bg-app-card p-4 text-sm font-semibold transition-[color,background-color,border-color,box-shadow] duration-200 hover:shadow-md has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand">
                                <input type="radio" name="category" value="{{ $name }}" class="size-5 accent-brand" @checked($answers->category === $name)>
                                {{ $name }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="grid gap-6 md:grid-cols-3">
                    <fieldset>
                        <legend class="font-display text-xl font-semibold">2. How many people?</legend>
                        <div class="mt-3">
                            <x-ui.input label="Number of people" name="people" type="number" min="1" max="500" step="1" inputmode="numeric" :value="$answers->people" />
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend class="font-display text-xl font-semibold">3. What is your budget?</legend>
                        <div class="mt-3">
                            <x-ui.input label="Budget (₱)" name="budget" type="number" min="1" max="99999999" step="0.01" inputmode="decimal"
                                :value="$answers->toQuery()['budget'] ?? null" hint="Packages priced on request may still fit, because the studio confirms their price." />
                        </div>
                    </fieldset>
                    @if ($themes->isNotEmpty())
                        <fieldset>
                            <legend class="font-display text-xl font-semibold">4. Any backdrop in mind?</legend>
                            <div class="mt-3">
                                <x-ui.select label="Backdrop" name="theme">
                                    <option value="">No preference</option>
                                    @foreach ($themes as $theme)
                                        <option value="{{ $theme->id }}" @selected($answers->theme === $theme->id)>{{ $theme->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>
                        </fieldset>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="submit"><x-ui.icon name="auto_awesome" :size="20" />Show suggestions</x-ui.button>
                    @if ($asked)
                        <x-ui.button :href="route('packages.finder')" variant="ghost">Start over</x-ui.button>
                    @endif
                </div>
            </form>

            @if ($asked)
                <section class="mt-12" aria-labelledby="results-heading">
                    <h2 id="results-heading" class="font-display text-2xl font-semibold">{{ $exact ? 'Packages that fit your answers' : 'Your suggestions' }}</h2>

                    @if ($results->isEmpty())
                        <x-ui.card class="mt-4">
                            <x-ui.empty-state icon="search_off" title="No package matches those answers" description="Try a different occasion, a bigger budget or fewer people, or browse every package.">
                                <x-ui.button :href="route('packages.index')" variant="outline">Browse all packages</x-ui.button>
                            </x-ui.empty-state>
                        </x-ui.card>
                    @else
                        @unless ($exact)
                            <x-ui.notice class="mt-4"><p>No package matches every answer. These are the closest, with what does not match.</p></x-ui.notice>
                        @endunless

                        <ol class="mt-4 grid gap-5 sm:grid-cols-2">
                            @foreach ($results as $result)
                                @php $package = $result->package; @endphp
                                <li class="flex">
                                    <x-ui.card hover design class="flex w-full flex-col">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <span class="rounded bg-neutral-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-app-text-2 dark:bg-white/10">{{ $package->category }}</span>
                                                <h3 class="mt-2 font-display text-xl font-semibold leading-snug"><a href="{{ route('packages.show', $package->slug) }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">{{ $package->name }}</a></h3>
                                            </div>
                                            @if ($result->percent() !== null)
                                                <x-ui.badge :tone="$result->isFullMatch() ? 'green' : 'amber'" :dot="false" class="shrink-0">{{ $result->percent() }}% match</x-ui.badge>
                                            @endif
                                        </div>

                                        <p class="mt-2 text-sm text-app-text-2">{{ $package->duration_minutes }} mins · up to {{ $package->max_people }} {{ $package->max_people === 1 ? 'person' : 'people' }} · <span class="font-semibold text-app-text tabular-nums">{{ $package->priceText() }}</span></p>

                                        @if ($result->reasons)
                                            <div class="mt-4">
                                                <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Why this fits your answers</p>
                                                <ul class="mt-2 space-y-1.5 text-sm">
                                                    @foreach ($result->reasons as $reason)
                                                        <li class="flex gap-2"><x-ui.icon name="check_circle" :size="18" class="mt-0.5 shrink-0 text-status-green" /><span>{{ $reason }}</span></li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif

                                        @if ($result->misses)
                                            <div class="mt-4">
                                                <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">What does not match</p>
                                                <ul class="mt-2 space-y-1.5 text-sm text-app-text-2">
                                                    @foreach ($result->misses as $miss)
                                                        <li class="flex gap-2"><x-ui.icon name="remove_circle_outline" :size="18" class="mt-0.5 shrink-0 text-app-muted" /><span>{{ $miss }}</span></li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif

                                        <div class="mt-auto pt-5">
                                            <div class="flex flex-wrap gap-2">
                                                <x-ui.button :href="route('packages.show', $package->slug)" variant="outline" size="sm">See details</x-ui.button>
                                                <x-package.book-button :package="$package" size="sm" />
                                            </div>
                                        </div>
                                    </x-ui.card>
                                </li>
                            @endforeach
                        </ol>
                    @endif

                    <x-ui.notice variant="payment" class="mt-8">
                        <p class="text-app-text-2">These are suggestions only. A suggestion is not a booking or a quotation. The studio confirms the schedule and the final price after reviewing a request.</p>
                    </x-ui.notice>
                </section>
            @endif
        @endif
    </section>
@endsection
