@extends('layouts.owner')

@section('title', 'Edit '.$package->name)

@section('main')
    <div x-data="{ target: { name: '', action: '' } }">
        <a href="{{ route('owner.packages.index') }}" class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-app-text-2 hover:text-brand focus-ring rounded-control">
            <x-ui.icon name="arrow_back" :size="18" /> All packages
        </a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="font-display text-3xl font-semibold sm:text-4xl">{{ $package->name }}</h1>
            <x-ui.badge :tone="$package->is_published ? 'green' : 'gray'">{{ $package->is_published ? 'Published' : 'Not published' }}</x-ui.badge>
            @if ($package->is_featured)
                <x-ui.badge tone="amber">Featured</x-ui.badge>
            @endif
        </div>

        <div class="mt-8 max-w-6xl space-y-10">
            @include('owner.packages._form', ['action' => route('owner.packages.update', $package), 'method' => 'PUT', 'submitLabel' => 'Save changes', 'deleteTarget' => $package])

            @include('owner.packages._gallery')

            <x-ui.notice variant="payment" />
        </div>

        <x-ui.modal name="delete-image" title="Delete this photo?">
            <p class="text-app-text-2">
                You are about to delete <strong class="text-app-text" x-text="target.name"></strong>. The photo file is removed too.
            </p>
            <form id="delete-image-form" method="POST" x-bind:action="target.action">
                @csrf
                @method('DELETE')
            </form>
            <x-slot:footer>
                <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="delete-image-form" variant="danger">Delete photo</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

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
