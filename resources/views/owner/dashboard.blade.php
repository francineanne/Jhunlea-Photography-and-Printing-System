@extends('layouts.owner')

@section('title', 'Owner overview')

@section('main')
    <h1 class="font-display text-3xl font-semibold sm:text-4xl">Welcome, {{ auth()->user()->name }}</h1>
    <p class="mt-2 text-app-text-2">This is the owner overview.</p>

    <x-ui.card class="mt-8">
        <x-ui.empty-state icon="inbox" title="Nothing here yet" description="Orders, bookings and shop tools will show up here when they are available." />
    </x-ui.card>
@endsection
