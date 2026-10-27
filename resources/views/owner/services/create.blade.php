@extends('layouts.owner')

@section('title', 'New service')

@section('main')
    <a href="{{ route('owner.services.index') }}" class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-app-text-2 hover:text-brand focus-ring rounded-control">
        <x-ui.icon name="arrow_back" :size="18" /> All services
    </a>
    <h1 class="mt-2 font-display text-3xl font-semibold sm:text-4xl">New printing service</h1>
    <p class="mt-2 text-app-text-2">Save the service first, then add its options (paper sizes, finishes and so on).</p>

    <div class="mt-8 max-w-6xl">
        @include('owner.services._form', ['action' => route('owner.services.store'), 'method' => 'POST', 'submitLabel' => 'Create service'])

        <x-ui.notice variant="payment" class="mt-10" />
    </div>
@endsection
