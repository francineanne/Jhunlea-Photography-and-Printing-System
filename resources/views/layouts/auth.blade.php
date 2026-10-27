@extends('layouts.app')

{{-- Pages fill the `auth` section; `title` is picked up by layouts.app. No page loader here. --}}
@section('content')
    <x-ui.toast />

    <div class="min-h-dvh lg:grid lg:grid-cols-2">
        {{-- Brand panel: desktop only, always dark --}}
        <aside class="dark relative hidden overflow-hidden bg-gradient-to-br from-ink via-neutral-900 to-[#3a0d0d] p-12 text-app-text lg:flex lg:flex-col lg:justify-between">
            <x-ui.logo size="lg" />

            <div class="relative z-10 max-w-md">
                <h2 class="font-display text-4xl font-semibold leading-tight">Prints and photoshoots, handled in one place.</h2>
                <p class="mt-4 text-app-text-2">Send a printing request, book a photoshoot, and follow each step with your reference number.</p>
            </div>

            <x-ui.notice variant="payment" class="relative z-10 max-w-md border-white/10 bg-white/5" />

            <x-ui.aperture-spinner :size="560" class="pointer-events-none absolute -bottom-40 -right-40 animate-none! text-brand/20" />
        </aside>

        <main class="relative flex min-h-dvh flex-col overflow-hidden px-4 py-4 sm:px-8">
            <div class="pointer-events-none absolute -left-24 top-1/4 size-72 rounded-full bg-brand/10 blur-3xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-24 right-0 size-72 rounded-full bg-status-violet/10 blur-3xl" aria-hidden="true"></div>

            <div class="relative flex items-center justify-between lg:justify-end">
                <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center rounded-control focus-ring lg:hidden" aria-label="Jhunlea Photography &amp; Printing"><x-ui.logo /></a>
                <x-ui.theme-toggle />
            </div>

            <div class="relative flex flex-1 items-center justify-center py-8">
                <div class="w-full max-w-md rounded-card border border-app-border bg-app-card/80 p-6 shadow-xl backdrop-blur-[16px] sm:p-8">
                    @yield('auth')
                </div>
            </div>
        </main>
    </div>
@endsection
