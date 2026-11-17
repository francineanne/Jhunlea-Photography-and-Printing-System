@extends('layouts.app')

{{-- Customer shell. Pages fill the `main` section. Add a navigation item by adding one line here. --}}
@php
    // One entry = label, icon, route name
    $nav = [
        ['label' => 'Dashboard', 'icon' => 'dashboard', 'route' => 'customer.dashboard'],
        ['label' => 'Request printing', 'icon' => 'print', 'route' => 'customer.printing.create', 'match' => 'customer.printing.create'],
        ['label' => 'Book a photoshoot', 'icon' => 'photo_camera', 'route' => 'customer.bookings.create', 'match' => ['customer.bookings.create', 'customer.bookings.sent']],
        ['label' => 'My requests', 'icon' => 'history', 'route' => 'customer.requests.index'],
        ['label' => 'My bookings', 'icon' => 'event_note', 'route' => 'customer.bookings.index', 'match' => ['customer.bookings.index', 'customer.bookings.show']],
        ['label' => 'My orders', 'icon' => 'receipt_long', 'route' => 'customer.printing.index', 'match' => ['customer.printing.index', 'customer.printing.show', 'customer.printing.placed']],
    ];

    // The menu of phones and tablets also lists the chat page. The top bar has no room for a seventh item at 1280px; there
    // the floating chat links to the page
    $menu = [...$nav, ['label' => 'Messages', 'icon' => 'forum', 'route' => 'customer.assistant.page']];

    // The floating chat, except on the full chat page, which is the chat itself
    $showChat = ! request()->routeIs('customer.assistant.page');
@endphp

@section('content')
    <x-layout.skip-link />
    <div x-data="liveUpdates(@js(route('customer.live')))" hidden></div>
    <x-ui.toast />
    <x-ui.confirm />
    <x-ui.lightbox />

    <header class="site-header sticky top-0 z-40 bg-app-bg/90 shadow-[0_1px_8px_rgba(0,0,0,0.04)] backdrop-blur-xl">
        <div class="mx-auto flex h-16 max-w-[1280px] items-center justify-between gap-3 px-4 sm:h-20 sm:px-6">
            <div class="flex items-center gap-2 xl:gap-8">
                <button type="button" x-on:click="$dispatch('open-modal', 'customer-menu')" aria-haspopup="dialog" aria-label="Open menu"
                    class="grid size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card focus-ring xl:hidden">
                    <x-ui.icon name="menu" />
                </button>
                <a href="{{ route('customer.dashboard') }}" class="group inline-flex min-h-11 min-w-11 items-center rounded-control focus-ring" aria-label="Jhunlea Photography &amp; Printing, my dashboard">
                    <x-ui.logo mark compact tight />
                </a>
                {{-- From 1280px, as in the customer designs (hidden xl:flex): four items and the account menu need the room --}}
                <x-layout.nav :items="$nav" variant="top" :icons="false" class="hidden xl:block" />
            </div>

            <div class="flex items-center gap-2">
                <span data-live="bell"><x-notifications.bell :bell="$bell" area="customer" /></span>
                <x-ui.theme-toggle />
                <x-layout.user-menu :user="auth()->user()" tight />
            </div>
        </div>
    </header>

    <x-layout.drawer name="customer-menu" label="Menu">
        <x-slot:heading><x-ui.logo /></x-slot:heading>
        <x-layout.nav :items="$menu" label="Menu" />
    </x-layout.drawer>

    {{-- Phase 10D, live updates: the lists, details and dashboard refresh themselves when the studio changes something
         (never a wizard or a form page). Without scripts nothing polls and the page is simply as it was loaded --}}
    <main id="main-content" tabindex="-1" class="mx-auto max-w-[1280px] px-4 py-8 focus:outline-none sm:px-6 sm:py-10"
        @if (request()->routeIs(...['customer.dashboard', 'customer.printing.index', 'customer.printing.show', 'customer.bookings.index', 'customer.bookings.show', 'customer.requests.index', 'customer.notifications.index'])) data-live="page" @endif>
        @yield('main')
    </main>

    <footer @class(['mx-auto max-w-[1280px] px-4 sm:px-6', 'pb-24' => $showChat, 'pb-8' => ! $showChat])>
        {{-- Phase 10D: one quiet line, built from the current payment rules --}}
        <p class="flex items-start gap-2 border-t border-app-border pt-4 text-xs text-app-text-2">
            <x-ui.icon name="storefront" :size="16" class="mt-px shrink-0 text-brand" />
            <span class="min-w-0">{{ $policyLine ?? 'No online payment. Pay at the shop in cash or by GCash.' }} <a href="{{ route('terms') }}" class="font-semibold text-brand underline-offset-2 hover:underline focus-ring">Terms and privacy</a></span>
        </p>
        <p class="mt-2 text-xs text-app-text-2">&copy; {{ date('Y') }} Jhunlea Photography &amp; Printing. All rights reserved.</p>
    </footer>

    @if ($showChat)
        <x-chat.window mode="compact" />
    @endif
@endsection
