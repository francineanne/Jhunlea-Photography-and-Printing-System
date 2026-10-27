@extends('layouts.owner')

@section('title', 'New package')

@section('main')
    <a href="{{ route('owner.packages.index') }}" class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-app-text-2 hover:text-brand focus-ring rounded-control">
        <x-ui.icon name="arrow_back" :size="18" /> All packages
    </a>
    <h1 class="mt-2 font-display text-3xl font-semibold sm:text-4xl">New photography package</h1>
    <p class="mt-2 text-app-text-2">Save the package first, then add its photos.</p>

    <div class="mt-8 max-w-6xl">
        @include('owner.packages._form', ['action' => route('owner.packages.store'), 'method' => 'POST', 'submitLabel' => 'Create package'])

        <x-ui.notice variant="payment" class="mt-10" />
    </div>
@endsection
