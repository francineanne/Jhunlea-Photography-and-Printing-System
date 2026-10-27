@extends('layouts.owner')

@section('title', 'Edit '.$service->name)

@section('main')
    <div x-data="{ target: { name: '', action: '' } }">
        <a href="{{ route('owner.services.index') }}" class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-app-text-2 hover:text-brand focus-ring rounded-control">
            <x-ui.icon name="arrow_back" :size="18" /> All services
        </a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="font-display text-3xl font-semibold sm:text-4xl">{{ $service->name }}</h1>
            <x-ui.badge :tone="$service->is_published ? 'green' : 'gray'">{{ $service->is_published ? 'Published' : 'Not published' }}</x-ui.badge>
        </div>

        <div class="mt-8 max-w-4xl space-y-10">
            @include('owner.services._form', ['action' => route('owner.services.update', $service), 'method' => 'PUT', 'submitLabel' => 'Save changes'])

            @include('owner.services._options')

            <x-ui.card>
                <h2 class="font-display text-xl font-semibold">Delete this service</h2>
                <p class="mt-1 text-sm text-app-text-2">Customers will no longer see it. Past requests keep their record of it.</p>
                <x-ui.button type="button" variant="danger" class="mt-4"
                    x-on:click="target = { name: {{ \Illuminate\Support\Js::from($service->name) }}, action: {{ \Illuminate\Support\Js::from(route('owner.services.destroy', $service)) }} }; $dispatch('open-modal', 'delete-service')">
                    <x-ui.icon name="delete" :size="18" /> Delete service
                </x-ui.button>
            </x-ui.card>
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

        <x-ui.modal name="remove-option" title="Remove this option?">
            <p class="text-app-text-2">
                You are about to remove the option <strong class="text-app-text" x-text="target.name"></strong> from this service.
            </p>
            <form id="remove-option-form" method="POST" x-bind:action="target.action">
                @csrf
                @method('DELETE')
            </form>
            <x-slot:footer>
                <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="remove-option-form" variant="danger">Remove option</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
@endsection
