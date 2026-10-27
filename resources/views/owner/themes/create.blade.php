@extends('layouts.owner')

@section('title', 'New theme')

@section('main')
    <a href="{{ route('owner.themes.index') }}" class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-app-text-2 hover:text-brand focus-ring rounded-control">
        <x-ui.icon name="arrow_back" :size="18" /> All themes
    </a>
    <h1 class="mt-2 font-display text-3xl font-semibold sm:text-4xl">New theme or backdrop</h1>
    <p class="mt-2 text-app-text-2">Describe the backdrop, add its colors, and link it to the packages that offer it.</p>

    <div class="mt-8 max-w-6xl">
        @include('owner.themes._form', ['action' => route('owner.themes.store'), 'method' => 'POST', 'submitLabel' => 'Create theme'])
    </div>
@endsection
