@extends('layouts.customer')

@section('title', 'Messages | '.config('app.name'))

@section('main')
    {{-- The chat fills the visible height under the site header (dynamic viewport height); only its list scrolls --}}
    <div class="-mt-2 mb-3 flex flex-wrap items-baseline justify-between gap-x-4 sm:-mt-4">
        <h1 class="font-display text-2xl font-semibold leading-tight sm:text-3xl">Messages</h1>
        <p class="hidden text-sm text-app-text-2 sm:block">Ask {{ config('assistant.name') }}, our AI assistant, or write to the studio (a person).</p>
    </div>

    {{-- 9C: the chat sends and loads messages with JavaScript --}}
    <noscript>
        <x-ui.notice class="mx-auto mb-3 max-w-4xl" data-noscript>
            <p><span class="font-semibold">The chat needs JavaScript.</span> Please turn on JavaScript in your browser settings, then reload this page.</p>
        </x-ui.notice>
    </noscript>

    <div class="mx-auto h-[calc(100dvh-9.5rem)] min-h-[26rem] max-w-4xl sm:h-[calc(100dvh-11.5rem)]">
        <x-chat.window mode="full" :tab="$tab" :about="$about" :draft="$draft" />
    </div>
@endsection
