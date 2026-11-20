@extends('layouts.app')

{{-- Owner shell. Pages fill the `main` section. Add a sidebar item by adding one line here. --}}
@php
    // One entry = label, icon, route name
    $nav = [
        ['label' => 'Overview', 'icon' => 'space_dashboard', 'route' => 'owner.dashboard'],
        // Phase 10J: the walk-in forms highlight Walk-in, not these two
        ['label' => 'Printing orders', 'icon' => 'receipt_long', 'route' => 'owner.orders.index', 'match' => ['owner.orders.index', 'owner.orders.show']],
        ['label' => 'Photography bookings', 'icon' => 'photo_camera_front', 'route' => 'owner.bookings.index', 'match' => ['owner.bookings.index', 'owner.bookings.show']],
        ['label' => 'Schedule', 'icon' => 'calendar_month', 'route' => 'owner.schedule.index', 'match' => 'owner.schedule.*'],
        ['label' => 'Payments', 'icon' => 'point_of_sale', 'route' => 'owner.payments.index', 'match' => 'owner.payments.*'],
        ['label' => 'Customers', 'icon' => 'group', 'route' => 'owner.customers.index', 'match' => 'owner.customers.*'],
        // Phase 10J: the one walk-in area, right after Customers (walk-ins are customers without an account)
        ['label' => 'Walk-in', 'icon' => 'storefront', 'route' => 'owner.walk-in.index', 'match' => ['owner.walk-in.*', 'owner.orders.walk-in.*', 'owner.bookings.walk-in.*']],
        ['label' => 'Reports', 'icon' => 'bar_chart', 'route' => 'owner.reports.income', 'match' => 'owner.reports.*'],
        ['label' => 'Messages', 'icon' => 'forum', 'route' => 'owner.messages.index', 'match' => 'owner.messages.*', 'badge' => $unanswered ?? 0],
        ['label' => 'Ask '.config('assistant.name'), 'icon' => 'camera', 'route' => 'owner.assistant.index', 'match' => 'owner.assistant.*'],
        ['label' => 'Notifications', 'icon' => 'notifications', 'route' => 'owner.notifications.index', 'match' => 'owner.notifications.*', 'badge' => $bell['unread'] ?? 0],
        ['label' => 'Services', 'icon' => 'print', 'route' => 'owner.services.index', 'match' => 'owner.services.*'],
        ['label' => 'Packages', 'icon' => 'photo_camera', 'route' => 'owner.packages.index', 'match' => 'owner.packages.*'],
        ['label' => 'Themes', 'icon' => 'palette', 'route' => 'owner.themes.index', 'match' => 'owner.themes.*'],
        ['label' => 'Settings', 'icon' => 'settings', 'route' => 'owner.settings.index', 'match' => ['owner.settings.*', 'owner.faqs.*', 'owner.activity.*']],
    ];
@endphp

@section('content')
    <x-layout.skip-link />
    {{-- Phase 10D: live updates (the same poll as the customer pages); owners only --}}
    @if (auth()->user()?->role === \App\Enums\UserRole::Owner)
        <div x-data="liveUpdates(@js(route('owner.live')))" hidden></div>
    @endif
    <x-ui.toast />
    <x-ui.confirm />
    {{-- Phase 10O: "Needs your attention", once after signing in, only when something is urgent --}}
    @if (! empty($urgent))
        <x-urgent.modal :urgent="$urgent" />
    @endif
    {{-- Keeps "Auto" presence honest: a ping only while this tab is visible and the owner really uses it (7D) --}}
    <div x-data="ownerHeartbeat(@js(route('owner.messages.presence.heartbeat')))" hidden></div>

    {{-- The sidebar is always dark (charcoal #2F3131), like the design --}}
    <aside id="owner-sidebar" class="owner-sidebar dark fixed inset-y-0 left-0 z-30 hidden w-72 flex-col justify-between bg-[#2F3131] p-4 text-app-text shadow-[0_1px_8px_rgba(0,0,0,0.04)] lg:flex">
        <div class="flex min-h-0 flex-col gap-6">
            <div class="sidebar-brand px-2 pt-2">
                <a href="{{ route('owner.dashboard') }}" class="inline-flex min-h-11 items-center rounded-control focus-ring" aria-label="Jhunlea Photography &amp; Printing, overview">
                    <span class="sidebar-full"><x-ui.logo /></span>
                    {{-- Phase 10D: only the mark while the sidebar is collapsed --}}
                    <span class="sidebar-mini"><x-ui.logo-mark /></span>
                </a>
                <p class="sidebar-full mt-2 inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2 py-0.5 font-mono text-[11px] font-medium uppercase tracking-wider text-[#ffb4ac]">
                    <span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span> Owner console
                </p>
            </div>
            <x-layout.nav :items="$nav" label="Owner" class="sidebar-scroll min-h-0 overflow-y-auto" x-init="$sidebarScroll($el)" />
        </div>

        <div class="sidebar-full flex gap-3 rounded-card bg-white/10 p-3 text-xs text-app-text-2">
            <x-ui.icon name="storefront" :size="20" class="mt-0.5 shrink-0 text-brand" />
            <p><span class="block font-semibold text-app-text">Recorded payments</span>Cash or GCash at the shop, or GCash a customer sent with its reference in Messages. You record each one.</p>
        </div>
    </aside>

    <x-layout.drawer name="owner-menu" label="Menu" class="dark">
        <x-slot:heading><x-ui.logo /></x-slot:heading>
        <x-layout.nav :items="$nav" label="Owner menu" />
    </x-layout.drawer>

    <div class="owner-shell min-h-dvh lg:pl-72">
        <header class="site-header sticky top-0 z-20 flex h-16 items-center justify-between gap-3 bg-app-bg/90 px-4 shadow-[0_1px_8px_rgba(0,0,0,0.04)] backdrop-blur-xl sm:px-6">
            <div class="flex items-center gap-2">
                <button type="button" x-on:click="$dispatch('open-modal', 'owner-menu')" aria-haspopup="dialog" aria-label="Open menu"
                    class="grid size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card focus-ring lg:hidden">
                    <x-ui.icon name="menu" />
                </button>
                {{-- 9C: below 420px only the mark shows (as in the customer header), so bell, theme and account fit a 320px phone --}}
                <a href="{{ route('owner.dashboard') }}" class="group inline-flex min-h-11 min-w-11 items-center rounded-control focus-ring lg:hidden" aria-label="Jhunlea Photography &amp; Printing, overview">
                    <x-ui.logo mark compact tight />
                </a>
                {{-- Phase 10D: folds the sidebar to icons (each names itself in a tooltip); remembered in this browser --}}
                <button type="button" x-data="sidebarToggle" x-cloak x-on:click="toggle()" x-bind:aria-expanded="(!collapsed).toString()" aria-controls="owner-sidebar"
                    x-bind:title="collapsed ? 'Show the menu labels' : 'Collapse the menu to icons'" x-bind:aria-label="collapsed ? 'Show the menu labels' : 'Collapse the menu to icons'"
                    class="hidden size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card text-app-text-2 transition-colors hover:text-app-text focus-ring lg:grid">
                    <span class="material-symbols-outlined text-[22px]" aria-hidden="true" x-text="collapsed ? 'menu' : 'menu_open'">menu_open</span>
                </button>
                <p class="hidden items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-app-text-2 lg:flex">Jhunlea <span aria-hidden="true">/</span> <span class="text-brand">Admin console</span></p>
            </div>

            <div class="flex items-center gap-2">
                <span data-live="bell"><x-notifications.bell :bell="$bell" area="owner" /></span>
                <x-ui.theme-toggle />
                <x-layout.user-menu :user="auth()->user()" />
            </div>
        </header>

        {{-- Phase 10D: bottom room for the floating Ask Lumi button, so it never covers the last field or button --}}
        <main id="main-content" tabindex="-1" class="px-4 pb-28 pt-8 focus:outline-none sm:px-6 sm:pt-10"
            @if (request()->routeIs(...['owner.dashboard', 'owner.orders.index', 'owner.orders.show', 'owner.bookings.index', 'owner.bookings.show', 'owner.payments.index', 'owner.schedule.index', 'owner.notifications.index', 'owner.customers.show'])) data-live="page" @endif>
            @yield('main')
        </main>
    </div>

    {{-- The owner's Ask Lumi panel (7E-3b), on every owner page except its own full page; owners only --}}
    @if (auth()->user()?->role === \App\Enums\UserRole::Owner && ! request()->routeIs('owner.assistant.*'))
        <x-owner.ask-lumi mode="compact" />
    @endif
@endsection
