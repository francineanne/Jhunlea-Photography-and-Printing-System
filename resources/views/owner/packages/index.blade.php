@extends('layouts.owner')

@section('title', 'Photography packages')

@section('main')
    <div x-data="{ target: { name: '', action: '' } }">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="h-0.5 w-6 bg-brand" aria-hidden="true"></span>Photography catalog</p>
                <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Photography packages</h1>
                <p class="mt-2 text-app-text-2">The sessions customers can book, with their price, time and inclusions.</p>
            </div>
            <x-ui.button :href="route('owner.packages.create')"><x-ui.icon name="add" :size="20" /> New package</x-ui.button>
        </div>

        @include('owner.packages._stats')

        @php
            // Chips and the search form keep each other's filters; changing one starts again from page 1
            $kept = array_filter(['search' => $search ?: null, 'status' => $status, 'featured' => $featured ? '1' : null]);
            $chip = 'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-4 text-sm font-semibold transition-colors focus-ring';
        @endphp

        <nav aria-label="Package categories" class="mt-8 flex flex-wrap gap-2">
            <a href="{{ route('owner.packages.index', $kept) }}" @if ($category === '') aria-current="true" @endif
                @class([$chip, 'border-ink bg-ink text-white dark:border-white dark:bg-white dark:text-ink' => $category === '', 'border-app-border bg-app-card hover:border-neutral-400' => $category !== ''])>
                All <span class="font-mono text-xs opacity-70">{{ $categories->sum() }}</span>
            </a>
            @foreach ($categories as $key => $total)
                @php $name = (string) $key; @endphp
                <a href="{{ route('owner.packages.index', $kept + ['category' => $name]) }}" @if ($category === $name) aria-current="true" @endif
                    @class([$chip, 'border-ink bg-ink text-white dark:border-white dark:bg-white dark:text-ink' => $category === $name, 'border-app-border bg-app-card hover:border-neutral-400' => $category !== $name])>
                    {{ $name }} <span class="font-mono text-xs opacity-70">{{ $total }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('owner.packages.index') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto_auto] lg:items-end">
            @if ($category !== '')
                <input type="hidden" name="category" value="{{ $category }}">
            @endif
            <x-ui.input label="Search by name" name="search" type="search" icon="search" :value="$search" placeholder="Type a package name" />
            <x-ui.select label="Status" name="status">
                <option value="">All</option>
                <option value="published" @selected($status === 'published')>Published</option>
                <option value="draft" @selected($status === 'draft')>Not published</option>
            </x-ui.select>
            <x-ui.checkbox name="featured" value="1" label="Featured only" :checked="$featured" />
            <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
                <x-ui.button type="submit">Filter</x-ui.button>
                @if ($hasFilters)
                    <x-ui.button :href="route('owner.packages.index')" variant="ghost">Clear</x-ui.button>
                @endif
            </div>
        </form>

        <div class="mt-6">
            @if ($packages->isEmpty())
                <x-ui.card>
                    @if ($hasFilters)
                        <x-ui.empty-state icon="search_off" title="No packages found" description="Nothing matches your search or filters. Try different words, or clear the filters.">
                            <x-ui.button :href="route('owner.packages.index')" variant="outline">Clear filters</x-ui.button>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="photo_camera" title="No packages yet" description="Add your first photography package to start building the catalog.">
                            <x-ui.button :href="route('owner.packages.create')">New package</x-ui.button>
                        </x-ui.empty-state>
                    @endif
                </x-ui.card>
            @else
                <ul class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3 min-[1700px]:grid-cols-4">
                    @foreach ($packages as $package)
                        <li class="flex">
                            <x-ui.card hover :padded="false" class="flex w-full flex-col">
                                <x-slot:image>
                                    <div class="relative aspect-[4/3]">
                                        @if ($package->coverImage)
                                            <img src="{{ $package->coverImage->url() }}" alt="{{ $package->coverImage->alt_text ?: $package->name }}" loading="lazy" class="size-full object-cover">
                                        @else
                                            <x-ui.image-placeholder class="size-full" />
                                        @endif
                                        <span class="absolute left-3 top-3 rounded-full bg-brand px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-white shadow-sm">{{ $package->category }}</span>
                                    </div>
                                </x-slot:image>

                                <div class="flex flex-1 flex-col p-5">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <a href="{{ route('owner.packages.edit', $package) }}" class="inline-flex min-h-11 items-center font-display text-xl font-medium leading-7 hover:text-brand focus-ring rounded-control">{{ $package->name }}</a>
                                        <x-ui.badge :tone="$package->is_published ? 'green' : 'gray'">{{ $package->is_published ? 'Published' : 'Not published' }}</x-ui.badge>
                                        @if ($package->is_featured)
                                            <x-ui.badge tone="amber">Featured</x-ui.badge>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm font-semibold tabular-nums">{{ $package->priceText() }}</p>
                                    <p class="mt-2 line-clamp-2 text-sm text-app-text-2">{{ $package->short_description }}</p>

                                    <dl class="mb-5 mt-4 space-y-1.5 text-sm text-app-text-2">
                                        <div class="flex items-center gap-2"><x-ui.icon name="schedule" :size="16" /><dt class="sr-only">Duration</dt><dd>{{ $package->duration_minutes }} mins</dd></div>
                                        <div class="flex items-center gap-2"><x-ui.icon name="group" :size="16" /><dt class="sr-only">Capacity</dt><dd>{{ $package->max_people === 1 ? '1 person' : "Up to {$package->max_people} people" }}</dd></div>
                                        @if ($package->digital_outputs)
                                            <div class="flex items-center gap-2"><x-ui.icon name="photo_library" :size="16" /><dt class="sr-only">Digital photos</dt><dd>{{ $package->digital_outputs }} edited digital photos</dd></div>
                                        @endif
                                    </dl>

                                    <div class="mt-auto flex items-center justify-between gap-1 border-t border-app-border pt-3">
                                        <x-ui.switch :action="route('owner.packages.publish', $package)" :on="$package->is_published" :name="$package->name" />
                                        <div class="flex items-center">
                                            <form method="POST" action="{{ route('owner.packages.feature', $package) }}">
                                                @csrf
                                                <button type="submit" aria-pressed="{{ $package->is_featured ? 'true' : 'false' }}"
                                                    class="grid size-11 place-items-center rounded-control hover:bg-black/5 focus-ring dark:hover:bg-white/10 {{ $package->is_featured ? 'text-brand' : 'text-app-text-2' }}">
                                                    <x-ui.icon name="star" :size="20" />
                                                    <span class="sr-only">Featured: {{ $package->name }}, {{ $package->is_featured ? 'on' : 'off' }}. Press to switch {{ $package->is_featured ? 'off' : 'on' }}.</span>
                                                </button>
                                            </form>
                                            <a href="{{ route('owner.packages.edit', $package) }}" class="grid size-11 place-items-center rounded-control text-app-text-2 hover:bg-black/5 hover:text-brand focus-ring dark:hover:bg-white/10" aria-label="Edit {{ $package->name }}"><x-ui.icon name="edit" :size="20" /></a>
                                            <button type="button" class="grid size-11 place-items-center rounded-control text-app-text-2 hover:bg-black/5 hover:text-status-red focus-ring dark:hover:bg-white/10" aria-label="Delete {{ $package->name }}"
                                                x-on:click="target = { name: {{ \Illuminate\Support\Js::from($package->name) }}, action: {{ \Illuminate\Support\Js::from(route('owner.packages.destroy', $package)) }} }; $dispatch('open-modal', 'delete-package')"><x-ui.icon name="delete" :size="20" /></button>
                                        </div>
                                    </div>
                                </div>
                            </x-ui.card>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-6">{{ $packages->links('pagination.simple') }}</div>
            @endif
        </div>

        <x-ui.modal name="delete-package" title="Delete this package?">
            <p class="text-app-text-2">
                You are about to delete <strong class="text-app-text" x-text="target.name"></strong>.
                Customers will no longer see it, and it cannot be restored from this screen.
            </p>
            <form id="delete-package-form" method="POST" x-bind:action="target.action">
                @csrf
                @method('DELETE')
            </form>
            <x-slot:footer>
                <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="delete-package-form" variant="danger">Delete package</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
@endsection
