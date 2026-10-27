@extends('layouts.customer')

@section('title', 'My dashboard')

@section('main')
    <h1 class="font-display text-3xl font-semibold sm:text-4xl">Welcome, {{ auth()->user()->name }}</h1>
    <p class="mt-2 text-app-text-2">This is your account home.</p>

    <x-ui.card class="mt-8">
        <x-ui.empty-state icon="inbox" title="Nothing here yet" description="Your requests and bookings will show up here when they are available." />
    </x-ui.card>
@endsection
