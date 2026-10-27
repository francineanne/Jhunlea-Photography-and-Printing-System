@extends('layouts.owner')

@section('title', 'Edit '.$theme->name)

@section('main')
    <div x-data="{ target: { name: '', action: '' } }">
        <a href="{{ route('owner.themes.index') }}" class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-app-text-2 hover:text-brand focus-ring rounded-control">
            <x-ui.icon name="arrow_back" :size="18" /> All themes
        </a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="font-display text-3xl font-semibold sm:text-4xl">{{ $theme->name }}</h1>
            <x-ui.badge :tone="$theme->is_published ? 'green' : 'gray'">{{ $theme->is_published ? 'Published' : 'Not published' }}</x-ui.badge>
        </div>

        <div class="mt-8 max-w-6xl">
            @include('owner.themes._form', ['action' => route('owner.themes.update', $theme), 'method' => 'PUT', 'submitLabel' => 'Save changes', 'deleteTarget' => $theme])
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
