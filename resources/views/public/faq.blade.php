@extends('layouts.public')

@section('title', 'Frequently asked questions | '.config('app.name'))

{{--
    The public FAQ (Phase 6B): the owner's published questions in order. Each one is a native <details>, so it opens
    and closes without JavaScript and is read by screen readers as a disclosure. Answers are plain text: escaped, with
    their line breaks kept by CSS.
--}}
<x-seo
    :title="'Frequently asked questions | '.config('app.name')"
    :description="'Answers to the questions customers ask '.config('app.name').' most: printing, photoshoots, bookings and payment at the shop.'"
    :noindex="$faqs->isEmpty()" />

@section('main')
    <section class="mx-auto max-w-[1280px] px-4 pb-16 pt-8 sm:px-6 sm:pt-12">
        <nav aria-label="Breadcrumb" class="mb-6 text-xs font-semibold uppercase tracking-wider text-app-text-2">
            <ol class="flex flex-wrap items-center gap-1.5">
                <li><a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Home</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="text-brand">FAQ</li>
            </ol>
        </nav>

        <header class="max-w-2xl">
            <p class="text-xs font-bold uppercase tracking-widest text-brand">Help</p>
            <h1 class="mt-2 font-display text-3xl font-semibold leading-tight sm:text-5xl">Frequently asked questions</h1>
            <p class="mt-3 text-app-text-2">Quick answers about printing, photoshoots and paying at the shop.</p>
        </header>

        @if ($faqs->isEmpty())
            <x-ui.card class="mt-8">
                <x-ui.empty-state icon="quiz" title="No questions here yet" description="Please check back soon, or ask the shop directly.">
                    <x-ui.button :href="route('services.index')" variant="outline">See printing services</x-ui.button>
                    <x-ui.button :href="route('packages.index')" variant="outline">See photography packages</x-ui.button>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <div class="mt-8 max-w-3xl space-y-3">
                @foreach ($faqs as $faq)
                    <details class="group animate-fade-up rounded-card border border-app-border bg-app-card shadow-sm transition-shadow hover:shadow-md open:border-brand/40 dark:shadow-none" style="animation-delay: {{ min($loop->index, 10) * 40 }}ms">
                        <summary class="flex min-h-14 cursor-pointer list-none items-start justify-between gap-4 rounded-card px-4 py-4 font-semibold focus-ring sm:px-5 [&::-webkit-details-marker]:hidden">
                            <span class="min-w-0 break-words">{{ $faq->question }}</span>
                            <x-ui.icon name="expand_more" :size="22" class="shrink-0 text-brand transition-transform duration-300 group-open:rotate-180" />
                        </summary>
                        <div class="whitespace-pre-line break-words border-t border-app-border px-4 py-4 text-app-text-2 sm:px-5">{{ $faq->answer }}</div>
                    </details>
                @endforeach
            </div>
        @endif

        <x-ui.notice variant="payment" class="mt-8 max-w-3xl">
            <p class="text-app-text-2">Payment is made face-to-face at Jhunlea Photography &amp; Printing.</p>
        </x-ui.notice>
    </section>
@endsection
