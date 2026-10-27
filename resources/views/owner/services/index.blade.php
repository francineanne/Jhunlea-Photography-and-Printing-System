@extends('layouts.owner')

@section('title', 'Printing services')

@section('main')
    <div x-data="{ target: { name: '', action: '' } }">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="font-display text-3xl font-semibold sm:text-4xl">Printing services</h1>
                <p class="mt-2 text-app-text-2">The services customers can request, and their options.</p>
            </div>
            <x-ui.button :href="route('owner.services.create')"><x-ui.icon name="add" :size="20" /> New service</x-ui.button>
        </div>

        <form method="GET" action="{{ route('owner.services.index') }}" class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
            <x-ui.input label="Search by name" name="search" type="search" icon="search" :value="$search" placeholder="Type a service name" />
            <x-ui.select label="Category" name="category">
                <option value="">All categories</option>
                @foreach ($categories as $name)
                    <option value="{{ $name }}" @selected($category === $name)>{{ $name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Status" name="status">
                <option value="">All</option>
                <option value="published" @selected($status === 'published')>Published</option>
                <option value="draft" @selected($status === 'draft')>Not published</option>
            </x-ui.select>
            <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
                <x-ui.button type="submit">Filter</x-ui.button>
                @if ($hasFilters)
                    <x-ui.button :href="route('owner.services.index')" variant="ghost">Clear</x-ui.button>
                @endif
            </div>
        </form>

        <div class="mt-6">
            @if ($services->isEmpty())
                <x-ui.card>
                    @if ($hasFilters)
                        <x-ui.empty-state icon="search_off" title="No services found" description="Nothing matches your search or filters. Try different words, or clear the filters.">
                            <x-ui.button :href="route('owner.services.index')" variant="outline">Clear filters</x-ui.button>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="print" title="No services yet" description="Add your first printing service to start building the catalog.">
                            <x-ui.button :href="route('owner.services.create')">New service</x-ui.button>
                        </x-ui.empty-state>
                    @endif
                </x-ui.card>
            @else
                {{-- Table on large screens --}}
                <div class="hidden overflow-x-auto rounded-card border border-app-border bg-app-card lg:block">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Printing services</caption>
                        <thead class="border-b border-app-border text-xs uppercase tracking-wide text-app-text-2">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-semibold">Service</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Pricing</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Options</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                                <th scope="col" class="px-4 py-3 font-semibold"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-app-border">
                            @foreach ($services as $service)
                                <tr>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('owner.services.edit', $service) }}" class="font-semibold hover:text-brand focus-ring rounded-control">{{ $service->name }}</a>
                                        <p class="text-xs text-app-text-2">{{ $service->category }} · <span class="font-mono">{{ $service->slug }}</span></p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="tabular-nums font-semibold">{{ $service->base_price === null ? $service->pricing_type->label() : \App\Support\Money::format($service->base_price) }}</p>
                                        <p class="text-xs text-app-text-2">{{ $service->pricing_type->label() }}</p>
                                    </td>
                                    <td class="px-4 py-3 tabular-nums">{{ $service->options_count }}</td>
                                    <td class="px-4 py-3">
                                        <x-ui.badge :tone="$service->is_published ? 'green' : 'gray'">{{ $service->is_published ? 'Published' : 'Not published' }}</x-ui.badge>
                                    </td>
                                    <td class="px-4 py-3">@include('owner.services._actions')</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Stacked cards on phones and tablets --}}
                <ul class="space-y-4 lg:hidden">
                    @foreach ($services as $service)
                        <li>
                            <x-ui.card>
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <a href="{{ route('owner.services.edit', $service) }}" class="inline-flex min-h-11 items-center font-display text-lg font-semibold hover:text-brand focus-ring rounded-control">{{ $service->name }}</a>
                                        <p class="text-xs text-app-text-2">{{ $service->category }}</p>
                                    </div>
                                    <x-ui.badge :tone="$service->is_published ? 'green' : 'gray'">{{ $service->is_published ? 'Published' : 'Not published' }}</x-ui.badge>
                                </div>
                                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <dt class="text-xs text-app-text-2">Pricing</dt>
                                        <dd class="font-semibold tabular-nums">{{ $service->base_price === null ? $service->pricing_type->label() : \App\Support\Money::format($service->base_price) }}</dd>
                                        <dd class="text-xs text-app-text-2">{{ $service->pricing_type->label() }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-app-text-2">Options</dt>
                                        <dd class="font-semibold tabular-nums">{{ $service->options_count }}</dd>
                                    </div>
                                </dl>
                                <div class="mt-4">@include('owner.services._actions')</div>
                            </x-ui.card>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-6">{{ $services->links('pagination.simple') }}</div>
            @endif
        </div>

        <x-ui.modal name="delete-service" title="Delete this service?">
            <p class="text-app-text-2">
                You are about to delete <strong class="text-app-text" x-text="target.name"></strong>.
                Customers will no longer see it, and it cannot be restored from this screen.
            </p>
            <form id="delete-service-form" method="POST" x-bind:action="target.action">
                @csrf
                @method('DELETE')
            </form>
            <x-slot:footer>
                <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="delete-service-form" variant="danger">Delete service</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
@endsection
