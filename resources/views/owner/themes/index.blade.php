@extends('layouts.owner')

@section('title', 'Themes and backdrops')

@section('main')
    <div x-data="{ target: { name: '', action: '' } }">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="h-0.5 w-6 bg-brand" aria-hidden="true"></span>Photography catalog</p>
                <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Themes and backdrops</h1>
                <p class="mt-2 text-app-text-2">The backdrops and color moods customers can pick for their shoot.</p>
            </div>
            <x-ui.button :href="route('owner.themes.create')"><x-ui.icon name="add" :size="20" /> New theme</x-ui.button>
        </div>

        @include('owner.themes._stats')

        @php
            $kept = array_filter(['search' => $search ?: null]);
            $tab = 'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-4 text-sm font-semibold transition-colors focus-ring';
            $tabs = ['' => ['All', $stats['total']], 'published' => ['Published', $stats['published']], 'draft' => ['Not published', $stats['unpublished']]];
        @endphp

        <nav aria-label="Theme status" class="mt-8 flex flex-wrap gap-2">
            @foreach ($tabs as $key => [$label, $count])
                <a href="{{ route('owner.themes.index', $kept + ($key === '' ? [] : ['status' => $key])) }}" @if (($status ?? '') === $key) aria-current="true" @endif
                    @class([$tab, 'border-ink bg-ink text-white dark:border-white dark:bg-white dark:text-ink' => ($status ?? '') === $key, 'border-app-border bg-app-card hover:border-neutral-400' => ($status ?? '') !== $key])>
                    {{ $label }} <span class="font-mono text-xs opacity-70">{{ $count }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('owner.themes.index') }}" class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <x-ui.input label="Search by name" name="search" type="search" icon="search" :value="$search" placeholder="Type a theme name" />
            <div class="flex gap-2">
                <x-ui.button type="submit">Search</x-ui.button>
                @if ($hasFilters)
                    <x-ui.button :href="route('owner.themes.index')" variant="ghost">Clear</x-ui.button>
                @endif
            </div>
        </form>

        <div class="mt-6">
            @if ($themes->isEmpty())
                <x-ui.card>
                    @if ($hasFilters)
                        <x-ui.empty-state icon="search_off" title="No themes found" description="Nothing matches your search or tab. Try different words, or clear the filters.">
                            <x-ui.button :href="route('owner.themes.index')" variant="outline">Clear filters</x-ui.button>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="palette" title="No themes yet" description="Add your first theme or backdrop so it can be linked to packages.">
                            <x-ui.button :href="route('owner.themes.create')">New theme</x-ui.button>
                        </x-ui.empty-state>
                    @endif
                </x-ui.card>
            @else
                <ul class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3 min-[1700px]:grid-cols-4">
                    @foreach ($themes as $theme)
                        <li class="flex">
                            <x-ui.card hover :padded="false" class="flex w-full flex-col">
                                <x-slot:image>
                                    <div class="relative aspect-[4/3]">
                                        @if ($theme->imageUrl())
                                            <img src="{{ $theme->imageUrl() }}" alt="" loading="lazy" class="size-full object-cover">
                                        @else
                                            <x-ui.image-placeholder icon="palette" class="size-full" />
                                        @endif
                                        <span class="absolute bottom-3 left-3 flex -space-x-1.5" aria-hidden="true">
                                            @foreach ($theme->color_swatches as $color)
                                                <span class="size-7 rounded-full ring-2 ring-white shadow-sm dark:ring-neutral-900" style="background-color: {{ $color }}"></span>
                                            @endforeach
                                        </span>
                                    </div>
                                </x-slot:image>

                                <div class="flex flex-1 flex-col p-5">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <a href="{{ route('owner.themes.edit', $theme) }}" class="inline-flex min-h-11 items-center font-display text-xl font-medium leading-7 hover:text-brand focus-ring rounded-control">{{ $theme->name }}</a>
                                        <x-ui.badge :tone="$theme->is_published ? 'green' : 'gray'">{{ $theme->is_published ? 'Published' : 'Not published' }}</x-ui.badge>
                                    </div>
                                    @if ($theme->description)
                                        <p class="mt-2 line-clamp-2 text-sm text-app-text-2">{{ $theme->description }}</p>
                                    @endif
                                    <p class="mb-5 mt-3 flex items-center gap-2 text-sm text-app-text-2"><x-ui.icon name="link" :size="16" /> {{ $theme->packages_count }} {{ \Illuminate\Support\Str::plural('package', $theme->packages_count) }}</p>

                                    <div class="mt-auto flex items-center justify-between gap-1 border-t border-app-border pt-3">
                                        <x-ui.switch :action="route('owner.themes.publish', $theme)" :on="$theme->is_published" :name="$theme->name" />
                                        <div class="flex items-center">
                                            <a href="{{ route('owner.themes.edit', $theme) }}" class="grid size-11 place-items-center rounded-control text-app-text-2 hover:bg-black/5 hover:text-brand focus-ring dark:hover:bg-white/10" aria-label="Edit {{ $theme->name }}"><x-ui.icon name="edit" :size="20" /></a>
                                            <button type="button" class="grid size-11 place-items-center rounded-control text-app-text-2 hover:bg-black/5 hover:text-status-red focus-ring dark:hover:bg-white/10" aria-label="Delete {{ $theme->name }}"
                                                x-on:click="target = { name: {{ \Illuminate\Support\Js::from($theme->name) }}, action: {{ \Illuminate\Support\Js::from(route('owner.themes.destroy', $theme)) }} }; $dispatch('open-modal', 'delete-theme')"><x-ui.icon name="delete" :size="20" /></button>
                                        </div>
                                    </div>
                                </div>
                            </x-ui.card>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-6">{{ $themes->links('pagination.simple') }}</div>
            @endif
        </div>

        <x-ui.modal name="delete-theme" title="Delete this theme?">
            <p class="text-app-text-2">
                You are about to delete <strong class="text-app-text" x-text="target.name"></strong>.
                It will be taken off every package, and it cannot be restored from this screen.
            </p>
            <form id="delete-theme-form" method="POST" x-bind:action="target.action">
                @csrf
                @method('DELETE')
            </form>
            <x-slot:footer>
                <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="delete-theme-form" variant="danger">Delete theme</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
@endsection
