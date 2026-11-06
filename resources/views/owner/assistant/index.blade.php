@extends('layouts.owner')

@section('title', 'Ask '.config('assistant.name').' | '.config('app.name'))

@section('main')
    {{-- The chat fills the visible height under the top bar (dynamic viewport height); only its list scrolls (7F-2) --}}
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4">
        <h1 class="font-display text-2xl font-semibold leading-tight sm:text-3xl">Ask {{ config('assistant.name') }}</h1>
        <p class="text-sm text-app-text-2">Questions about your shop, answered from your own records. {{ config('assistant.name') }} is an AI and never changes anything.</p>
    </div>

    <div class="mx-auto h-[calc(100dvh-10rem)] min-h-[26rem] max-w-4xl sm:h-[calc(100dvh-11rem)]">
        <x-owner.ask-lumi mode="full" :history="$history" />
    </div>
@endsection
