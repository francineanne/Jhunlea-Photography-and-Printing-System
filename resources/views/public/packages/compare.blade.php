@extends('layouts.public')

@section('title', 'Compare packages | '.config('app.name'))

@php
    $slugs = $packages->pluck('slug')->all();
    $people = fn ($package) => 'Up to '.$package->max_people.' '.($package->max_people === 1 ? 'person' : 'people');

    // Each row: a label, what a package shows, and the plain value used to tell whether the packages differ
    $rows = [
        ['Category', fn ($p) => $p->category, fn ($p) => $p->category],
        ['Estimated price', fn ($p) => $p->priceText(), fn ($p) => $p->priceText()],
        ['Session length', fn ($p) => $p->duration_minutes.' mins', fn ($p) => $p->duration_minutes],
        ['Fits', $people, fn ($p) => $p->max_people],
        ['Digital photos', fn ($p) => $p->digital_outputs ? $p->digital_outputs.' edited '.($p->digital_outputs === 1 ? 'photo' : 'photos') : 'Not listed', fn ($p) => (int) $p->digital_outputs],
        ['Backdrops', fn ($p) => $p->themes->isEmpty() ? 'None listed' : $p->themes->pluck('name')->join(', '), fn ($p) => $p->themes->pluck('name')->sort()->join('|')],
    ];

    $rows = collect($rows)->map(function ($row) use ($packages) {
        [$label, $show, $key] = $row;

        return [
            'label' => $label,
            'cells' => $packages->map($show)->all(),
            'differs' => $packages->map($key)->unique()->count() > 1,
        ];
    });

    $listsDiffer = $packages->map(fn ($p) => collect($p->inclusions ?? [])->sort()->join('|'))->unique()->count() > 1;
    $shownRows = $differencesOnly ? $rows->where('differs', true) : $rows;
@endphp

<x-seo :title="'Compare packages | '.config('app.name')"
    description="Compare photography packages side by side: price, session length, how many people they fit and what is included."
    :noindex="true" />

@section('main')
    <section class="mx-auto max-w-[1280px] px-4 pb-16 pt-8 sm:px-6 sm:pt-12">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-x-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <nav aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-1.5">
                <li><a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Home</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('packages.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Photography packages</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="text-brand">Compare</li>
            </ol>
        </nav>
        <a href="{{ route('packages.index') }}" class="group inline-flex min-h-11 items-center gap-1.5 rounded normal-case tracking-normal transition-colors hover:text-brand focus-ring"><x-ui.icon name="arrow_back" :size="16" class="transition-transform group-hover:-translate-x-0.5" />Back to all packages</a>
        </div>

        <header class="max-w-2xl">
            <h1 class="font-display text-3xl font-semibold leading-tight sm:text-5xl">Compare packages</h1>
            <p class="mt-3 text-app-text-2">See {{ $min }} to {{ $max }} packages side by side. Rows where the packages differ are highlighted.</p>
        </header>

        @foreach ($notes as $note)
            <x-ui.notice class="mt-6"><p>{{ $note }}</p></x-ui.notice>
        @endforeach

        @if (! $ready)
            <x-ui.card class="mt-8">
                <x-ui.empty-state icon="compare_arrows" title="Choose at least {{ $min }} packages to compare"
                    description="{{ $packages->count() === 1 ? 'Only one package is ready to compare.' : 'No packages are chosen yet.' }} Go back to the list, tick Compare on {{ $min }} or {{ $max }} packages, then press Compare packages.">
                    <x-ui.button :href="route('packages.index')">Browse packages</x-ui.button>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <div x-data="{ dim: false }">
            <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-app-text-2" role="status">Comparing {{ $packages->count() }} packages.</p>
                {{-- Without scripts the link reloads the page with the other rows removed. With scripts it is replaced by a switch that dims them. --}}
                <a href="{{ route('packages.compare', ['packages' => $slugs] + ($differencesOnly ? [] : ['differences' => 1])) }}"
                    class="inline-flex min-h-11 items-center gap-2 rounded-control border border-app-border bg-app-card px-4 text-sm font-semibold transition-colors hover:bg-card focus-ring dark:hover:bg-white/10"
                    @if ($differencesOnly) aria-current="true" @else x-bind:hidden="true" @endif>
                    <x-ui.icon name="{{ $differencesOnly ? 'check_box' : 'check_box_outline_blank' }}" :size="20" class="text-brand" />
                    Show differences only
                </a>
                @unless ($differencesOnly)
                    <button type="button" role="switch" x-cloak x-on:click="dim = !dim" x-bind:aria-checked="dim.toString()"
                        class="inline-flex min-h-11 items-center gap-2 rounded-control border border-app-border bg-app-card px-4 text-sm font-semibold transition-colors hover:bg-card focus-ring dark:hover:bg-white/10">
                        <x-ui.icon name="check_box_outline_blank" :size="20" class="text-brand" x-show="!dim" />
                        <x-ui.icon name="check_box" :size="20" class="text-brand" x-show="dim" x-cloak />
                        Show differences only
                    </button>
                @endunless
            </div>

            <div class="relative mt-4 overflow-x-auto rounded-card border border-app-border bg-app-card" tabindex="0" role="region" aria-label="Comparison table, scroll sideways on small screens">
                <table class="w-full min-w-[40rem] table-fixed border-collapse text-left text-sm">
                    <caption class="sr-only">Comparison of {{ $packages->pluck('name')->join(', ') }}</caption>
                    <thead>
                        <tr class="align-top">
                            <th scope="col" class="w-32 p-4 sm:w-44"><span class="sr-only">Detail</span></th>
                            @foreach ($packages as $package)
                                <th scope="col" class="min-w-44 border-l border-app-border p-4 font-normal">
                                    <div class="aspect-[4/3] overflow-hidden rounded-control bg-card">
                                        @if ($package->coverImage)
                                            <img src="{{ $package->coverImage->url() }}" alt="" width="400" height="300" loading="lazy" decoding="async" class="size-full object-cover">
                                        @else
                                            <x-ui.image-placeholder class="size-full" />
                                        @endif
                                    </div>
                                    <a href="{{ route('packages.show', $package->slug) }}" class="mt-3 flex min-h-11 items-center font-display text-lg font-semibold leading-snug hover:text-brand focus-ring rounded">{{ $package->name }}</a>
                                    <a href="{{ route('packages.compare', ['packages' => array_values(array_diff($slugs, [$package->slug]))] + ($differencesOnly ? ['differences' => 1] : [])) }}"
                                        class="mt-1 inline-flex min-h-11 items-center gap-1 rounded text-xs font-semibold text-app-text-2 hover:text-brand focus-ring">
                                        <x-ui.icon name="close" :size="16" />Remove<span class="sr-only"> {{ $package->name }}</span>
                                    </a>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($shownRows as $row)
                            <tr @class(['border-t border-app-border transition-opacity duration-300', 'bg-brand/5' => $row['differs']]) @unless ($row['differs']) x-bind:class="dim ? 'opacity-20 grayscale' : ''" @endunless>
                                <th scope="row" class="p-4 align-top text-xs font-bold uppercase tracking-wide text-app-text-2">
                                    {{ $row['label'] }}
                                    @if ($row['differs'])<span class="mt-1 block text-[10px] normal-case tracking-normal text-brand">Differs</span>@endif
                                </th>
                                @foreach ($row['cells'] as $cell)
                                    <td class="border-l border-app-border p-4 align-top {{ $row['label'] === 'Estimated price' ? 'font-bold tabular-nums' : '' }}">{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr class="border-t border-app-border"><td colspan="{{ $packages->count() + 1 }}" class="p-4 text-app-text-2">These packages match on every detail above.</td></tr>
                        @endforelse

                        @if (! $differencesOnly || $listsDiffer)
                            <tr @class(['border-t border-app-border transition-opacity duration-300', 'bg-brand/5' => $listsDiffer]) @unless ($listsDiffer) x-bind:class="dim ? 'opacity-20 grayscale' : ''" @endunless>
                                <th scope="row" class="p-4 align-top text-xs font-bold uppercase tracking-wide text-app-text-2">
                                    What is included
                                    @if ($listsDiffer)<span class="mt-1 block text-[10px] normal-case tracking-normal text-brand">Differs</span>@endif
                                </th>
                                @foreach ($packages as $package)
                                    <td class="border-l border-app-border p-4 align-top">
                                        @if (! empty($package->inclusions))
                                            <ul class="space-y-1.5">
                                                @foreach ($package->inclusions as $item)
                                                    <li class="flex gap-2"><x-ui.icon name="check_circle" :size="18" class="mt-0.5 shrink-0 text-status-green" /><span>{{ $item }}</span></li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <span class="text-app-text-2">Not listed</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
            </div>

            <x-ui.notice variant="payment" class="mt-8">
                <p class="text-app-text-2">Prices are estimates. The studio confirms the final price after reviewing a request, and a package does not hold a date until the studio confirms the schedule.</p>
            </x-ui.notice>
        @endif
    </section>
@endsection
