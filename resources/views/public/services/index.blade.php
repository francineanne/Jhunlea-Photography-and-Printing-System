@extends('layouts.public')

@section('title', 'Printing services | '.config('app.name'))

@php
    $filtered = $search !== '' || $category !== '';
    $total = $categories->sum();
    $chipLink = fn (string $name) => route('services.index', array_filter(['category' => $name, 'q' => $search]));
    // Rules customers must follow, taken from the services themselves: the file types and sizes the owner allows
    $fileTypes = $services->flatMap(fn ($service) => $service->allowed_file_types ?? [])->unique()->sort()->values();
    $largest = $services->max('max_file_size_mb');
@endphp

<x-seo
    :title="'Printing services | '.config('app.name')"
    :description="'Browse the printing services of '.config('app.name').': what each one is, what it starts from and which files it accepts. Payment is made face-to-face.'"
    :noindex="$filtered || $services->currentPage() > 1" />

@section('main')
    <section class="mx-auto max-w-[1280px] px-4 pb-16 pt-8 sm:px-6 sm:pt-12">
        <nav aria-label="Breadcrumb" class="mb-6 text-xs font-semibold uppercase tracking-wider text-app-text-2">
            <ol class="flex flex-wrap items-center gap-1.5">
                <li><a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Home</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="text-brand">Printing services</li>
            </ol>
        </nav>

        <header class="max-w-2xl">
            <p class="text-xs font-bold uppercase tracking-widest text-brand">Printing</p>
            <h1 class="mt-2 font-display text-3xl font-semibold leading-tight sm:text-5xl">Printing services</h1>
            <p class="mt-3 text-app-text-2">See what we print, what each service starts from and which files we accept. Prices shown are where a service starts. The final price is confirmed after your request is reviewed.</p>
        </header>

        <x-ui.notice variant="payment" class="mt-8">
            <p class="text-app-text-2">Sending a request is not an accepted order. The owner reviews it first and confirms the price.</p>
        </x-ui.notice>

        @if ($total === 0)
            <x-ui.card class="mt-8">
                <x-ui.empty-state icon="print" title="No printing services are listed yet" description="Please check back soon, or contact the shop to ask what we can print.">
                    <x-ui.button :href="route('home')" variant="outline">Back to home</x-ui.button>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <div class="mt-8 rounded-card border border-app-border bg-app-card p-3 sm:p-4">
                <form method="GET" action="{{ route('services.index') }}" role="search" class="flex flex-col gap-3 sm:flex-row">
                    @if ($category !== '')
                        <input type="hidden" name="category" value="{{ $category }}">
                    @endif
                    <label for="service-search" class="sr-only">Search printing services</label>
                    <div class="relative flex-1">
                        <x-ui.icon name="search" :size="20" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-app-muted" />
                        <input id="service-search" type="search" name="q" value="{{ $search }}" maxlength="60" placeholder="Search by service name"
                            class="h-11 w-full rounded-control border border-app-border bg-app-bg pl-11 pr-4 text-sm focus-ring" autocomplete="off">
                    </div>
                    <x-ui.button type="submit">Search</x-ui.button>
                </form>

                <nav aria-label="Service categories" class="mt-3">
                    <ul class="flex flex-wrap gap-2">
                        <li>
                            <a href="{{ route('services.index', array_filter(['q' => $search])) }}" @if ($category === '') aria-current="true" @endif
                                @class([
                                    'inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold focus-ring transition-colors duration-150 sm:min-h-9',
                                    'bg-ink text-white dark:bg-white dark:text-ink' => $category === '',
                                    'border border-app-border bg-app-bg text-app-text-2 hover:text-app-text' => $category !== '',
                                ])>All services <span class="ml-1.5 tabular-nums opacity-70">{{ $total }}</span></a>
                        </li>
                        @foreach ($categories as $name => $count)
                            <li>
                                <a href="{{ $chipLink($name) }}" @if ($category === $name) aria-current="true" @endif
                                    @class([
                                        'inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold focus-ring transition-colors duration-150 sm:min-h-9',
                                        'bg-ink text-white dark:bg-white dark:text-ink' => $category === $name,
                                        'border border-app-border bg-app-bg text-app-text-2 hover:text-app-text' => $category !== $name,
                                    ])>{{ $name }} <span class="ml-1.5 tabular-nums opacity-70">{{ $count }}</span></a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </div>

            <p class="mt-6 text-sm text-app-text-2" role="status">
                @if ($services->total() === 0)
                    No services match{{ $search !== '' ? ' “'.$search.'”' : '' }}{{ $category !== '' ? ' in '.$category : '' }}.
                @else
                    Showing {{ $services->firstItem() }}–{{ $services->lastItem() }} of {{ $services->total() }} {{ \Illuminate\Support\Str::plural('service', $services->total()) }}
                @endif
            </p>

            @if ($services->isEmpty())
                <x-ui.card class="mt-4">
                    <x-ui.empty-state icon="search_off" title="Nothing matches your search" description="Try a shorter word or show all services.">
                        <x-ui.button :href="route('services.index')" variant="outline">Show all services</x-ui.button>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <ul class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($services as $service)
                        <li class="flex">
                            <x-ui.card hover design class="flex w-full flex-col">
                                <div class="flex items-start justify-between gap-3">
                                    <span class="grid size-11 shrink-0 place-items-center rounded-control bg-brand/10 text-brand" aria-hidden="true">
                                        <x-ui.icon name="print" :size="24" />
                                    </span>
                                    <span class="rounded bg-neutral-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-app-text-2 dark:bg-white/10">{{ $service->category }}</span>
                                </div>

                                <h2 class="mt-4 font-display text-xl font-semibold leading-snug transition-colors group-hover:text-brand">{{ $service->name }}</h2>
                                <p class="mt-0.5 font-mono text-xs uppercase tracking-wide text-brand">{{ $service->pricing_type->label() }}</p>

                                @if ($service->description)
                                    <p class="mt-3 text-sm leading-relaxed text-app-text-2">{{ $service->description }}</p>
                                @endif

                                @php $groups = $service->options->groupBy(fn ($option) => $option->group->value); @endphp
                                @if ($groups->isNotEmpty())
                                    <dl class="mt-4 space-y-2 text-sm">
                                        @foreach ($optionGroups as $group)
                                            @if ($groups->has($group->value))
                                                <div class="flex gap-3">
                                                    <dt class="w-24 shrink-0 text-app-text-2">{{ $group->label() }}</dt>
                                                    <dd class="min-w-0 font-medium">{{ $groups[$group->value]->pluck('label')->join(', ') }}</dd>
                                                </div>
                                            @endif
                                        @endforeach
                                    </dl>
                                @endif

                                @if ($service->instructions)
                                    <p class="mt-4 flex gap-2 rounded-control bg-neutral-100 p-3 text-xs leading-relaxed text-app-text-2 dark:bg-white/5">
                                        <x-ui.icon name="tips_and_updates" :size="16" class="mt-0.5 shrink-0 text-brand" />
                                        <span>{{ $service->instructions }}</span>
                                    </p>
                                @endif

                                <div class="mt-auto pt-5">
                                    <div class="border-t border-app-border pt-4">
                                        <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Estimated price</p>
                                        <p class="mt-0.5 text-lg font-bold tabular-nums">{{ $service->priceText() }}</p>
                                        @if ($service->allowed_file_types)
                                            <p class="mt-2 text-xs text-app-text-2">Files: <span class="font-mono uppercase">{{ implode(', ', $service->allowed_file_types) }}</span> · up to {{ $service->max_file_size_mb }} MB</p>
                                        @endif
                                    </div>
                                </div>
                            </x-ui.card>
                        </li>
                    @endforeach
                </ul>

                @if ($services->hasPages())
                    <div class="mt-8">{{ $services->links('pagination.simple') }}</div>
                @endif
            @endif

            @if ($fileTypes->isNotEmpty())
                <section class="mt-14" aria-labelledby="guidelines-heading">
                    <h2 id="guidelines-heading" class="font-display text-2xl font-semibold">Before you send files</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-ui.card>
                            <x-ui.icon name="description" :size="24" class="text-brand" />
                            <h3 class="mt-3 font-semibold">Accepted file types</h3>
                            <p class="mt-1 text-sm text-app-text-2">Each service lists the files it accepts. Across the services shown here:</p>
                            <p class="mt-2 font-mono text-sm uppercase">{{ $fileTypes->join(', ') }}</p>
                        </x-ui.card>
                        <x-ui.card>
                            <x-ui.icon name="upload_file" :size="24" class="text-brand" />
                            <h3 class="mt-3 font-semibold">File size</h3>
                            <p class="mt-1 text-sm text-app-text-2">The largest file allowed is set per service, up to {{ $largest }} MB for the services shown here.</p>
                        </x-ui.card>
                    </div>
                </section>
            @endif
        @endif
    </section>
@endsection
