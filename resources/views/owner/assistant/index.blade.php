@extends('layouts.owner')

@section('title', 'Ask '.config('assistant.name').' | '.config('app.name'))

@section('main')
    <div class="mb-4">
        <h1 class="font-display text-2xl font-semibold leading-tight sm:text-4xl">Ask {{ config('assistant.name') }}</h1>
        <p class="mt-1 text-sm text-app-text-2">Questions about your shop, answered from your own records. {{ config('assistant.name') }} is an AI and never changes anything.</p>
    </div>

    <div class="mx-auto h-[calc(100dvh-13rem)] min-h-[28rem] max-w-4xl sm:h-[calc(100dvh-16rem)]">
        <x-owner.ask-lumi mode="full" :history="$history" />
    </div>
@endsection
