@extends('layouts.owner')

@section('title', 'Add a question')

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('owner.settings.index') }}#faq" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Settings</a></li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ route('owner.faqs.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">FAQ</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">Add</li>
        </ol>
    </nav>

    <h1 class="font-display text-3xl font-semibold tracking-tight sm:text-4xl">Add a question</h1>
    <p class="mt-2 max-w-2xl text-app-text-2">It goes to the end of the list. You can move it afterwards.</p>

    <form method="POST" action="{{ route('owner.faqs.store') }}" class="mt-6 max-w-3xl rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none">
        @csrf
        @include('owner.faqs._form')
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <x-ui.button :href="route('owner.faqs.index')" variant="outline">Cancel</x-ui.button>
            <x-ui.button type="submit"><x-ui.icon name="save" :size="18" />Add question</x-ui.button>
        </div>
    </form>
@endsection
