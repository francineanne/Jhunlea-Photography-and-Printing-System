@extends('layouts.app')

@section('title', 'Owner dashboard')

{{-- Placeholder: Part C replaces this with the real owner layout --}}
@section('content')
    <x-ui.toast />

    <header class="border-b border-app-border">
        <div class="mx-auto flex max-w-[1280px] items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <x-ui.logo />
            <div class="flex items-center gap-2">
                <x-ui.theme-toggle />
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-ui.button type="submit" variant="outline">Log out</x-ui.button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-[1280px] space-y-6 px-4 py-10 sm:px-6">
        <h1 class="font-display text-3xl font-semibold sm:text-4xl">Welcome, {{ auth()->user()->name }}</h1>
        <x-ui.notice>Coming soon: orders, bookings and shop tools will appear here.</x-ui.notice>
    </main>
@endsection
