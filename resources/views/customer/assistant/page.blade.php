@extends('layouts.customer')

@section('title', 'Messages | '.config('app.name'))

@section('main')
    <div class="mb-4">
        <h1 class="font-display text-2xl font-semibold leading-tight sm:text-4xl">Messages</h1>
        <p class="mt-1 hidden text-sm text-app-text-2 sm:block">Ask {{ config('assistant.name') }}, our AI assistant, or write to the studio.</p>
    </div>

    <div class="mx-auto h-[calc(100dvh-12rem)] min-h-[28rem] max-w-4xl sm:h-[calc(100dvh-16rem)]">
        <x-chat.window mode="full" :tab="$tab" :about="$about" />
    </div>
@endsection
