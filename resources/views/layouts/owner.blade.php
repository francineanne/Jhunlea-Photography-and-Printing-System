@extends('layouts.app')

{{-- Owner shell. Pages fill the `main` section. Add a sidebar item by adding one line here. --}}
@php
    // One entry = label, icon, route name
    $nav = [
        ['label' => 'Overview', 'icon' => 'space_dashboard', 'route' => 'owner.dashboard'],
        ['label' => 'Printing orders', 'icon' => 'receipt_long', 'route' => 'owner.orders.index', 'match' => 'owner.orders.*'],
        ['label' => 'Photography bookings', 'icon' => 'photo_camera_front', 'route' => 'owner.bookings.index', 'match' => 'owner.bookings.*'],
        ['label' => 'Schedule', 'icon' => 'calendar_month', 'route' => 'owner.schedule.index', 'match' => 'owner.schedule.*'],
        ['label' => 'Payments', 'icon' => 'point_of_sale', 'route' => 'owner.payments.index', 'match' => 'owner.payments.*'],
        ['label' => 'Notifications', 'icon' => 'notifications', 'route' => 'owner.notifications.index', 'match' => 'owner.notifications.*', 'badge' => $bell['unread'] ?? 0],
        ['label' => 'Services', 'icon' => 'print', 'route' => 'owner.services.index', 'match' => 'owner.services.*'],
        ['label' => 'Packages', 'icon' => 'photo_camera', 'route' => 'owner.packages.index', 'match' => 'owner.packages.*'],
        ['label' => 'Themes', 'icon' => 'palette', 'route' => 'owner.themes.index', 'match' => 'owner.themes.*'],
    ];
@endphp

@section('content')
    <x-layout.skip-link />
    <x-ui.toast />

    {{-- The sidebar is always dark (charcoal #2F3131), like the design --}}
    <aside class="dark fixed inset-y-0 left-0 z-30 hidden w-72 flex-col justify-between bg-[#2F3131] p-4 text-app-text shadow-[0_1px_8px_rgba(0,0,0,0.04)] lg:flex">
        <div class="flex min-h-0 flex-col gap-6">
            <div class="px-2 pt-2">
                <a href="{{ route('owner.dashboard') }}" class="inline-flex min-h-11 items-center rounded-control focus-ring" aria-label="Jhunlea Photography &amp; Printing, overview">
                    <x-ui.logo />
                </a>
                <p class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2 py-0.5 font-mono text-[11px] font-medium uppercase tracking-wider text-[#ffb4ac]">
                    <span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span> Owner console
                </p>
            </div>
            <x-layout.nav :items="$nav" label="Owner" class="min-h-0 overflow-y-auto" />
        </div>

        <div class="flex gap-3 rounded-card bg-white/10 p-3 text-xs text-app-text-2">
            <x-ui.icon name="storefront" :size="20" class="mt-0.5 shrink-0 text-brand" />
            <p><span class="block font-semibold text-app-text">Face-to-face payments</span>Payment is made face-to-face at Jhunlea Photography &amp; Printing.</p>
        </div>
    </aside>

    <x-layout.drawer name="owner-menu" label="Menu" class="dark">
        <x-slot:heading><x-ui.logo /></x-slot:heading>
        <x-layout.nav :items="$nav" label="Owner menu" />
    </x-layout.drawer>

    <div class="min-h-dvh lg:pl-72">
        <header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 bg-app-bg/90 px-4 shadow-[0_1px_8px_rgba(0,0,0,0.04)] backdrop-blur-xl sm:px-6">
            <div class="flex items-center gap-2">
                <button type="button" x-on:click="$dispatch('open-modal', 'owner-menu')" aria-haspopup="dialog" aria-label="Open menu"
                    class="grid size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card focus-ring lg:hidden">
                    <x-ui.icon name="menu" />
                </button>
                <a href="{{ route('owner.dashboard') }}" class="inline-flex min-h-11 items-center rounded-control focus-ring lg:hidden" aria-label="Jhunlea Photography &amp; Printing, overview">
                    <x-ui.logo compact />
                </a>
                <p class="hidden items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-app-text-2 lg:flex">Jhunlea <span aria-hidden="true">/</span> <span class="text-brand">Admin console</span></p>
            </div>

            <div class="flex items-center gap-2">
                <x-notifications.bell :bell="$bell" area="owner" />
                <x-ui.theme-toggle />
                <x-layout.user-menu :user="auth()->user()" />
            </div>
        </header>

        <main id="main-content" tabindex="-1" class="px-4 py-8 focus:outline-none sm:px-6 sm:py-10">
            @yield('main')
        </main>
    </div>
@endsection
