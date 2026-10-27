@extends('layouts.app')

{{-- Owner shell. Pages fill the `main` section. Add a sidebar item by adding one line here. --}}
@php
    // One entry = label, icon, route name
    $nav = [
        ['label' => 'Overview', 'icon' => 'space_dashboard', 'route' => 'owner.dashboard'],
        ['label' => 'Services', 'icon' => 'print', 'route' => 'owner.services.index', 'match' => 'owner.services.*'],
    ];
@endphp

@section('content')
    <x-layout.skip-link />
    <x-ui.toast />

    {{-- The sidebar is always dark, like the design --}}
    <aside class="dark fixed inset-y-0 left-0 z-30 hidden w-64 flex-col gap-6 border-r border-app-border bg-app-bg p-4 text-app-text lg:flex">
        <a href="{{ route('owner.dashboard') }}" class="inline-flex min-h-11 items-center rounded-control px-1.5 focus-ring" aria-label="Jhunlea Photography &amp; Printing, overview">
            <x-ui.logo />
        </a>
        <x-layout.nav :items="$nav" label="Owner" />
    </aside>

    <x-layout.drawer name="owner-menu" label="Menu" class="dark">
        <x-slot:heading><x-ui.logo /></x-slot:heading>
        <x-layout.nav :items="$nav" label="Owner menu" />
    </x-layout.drawer>

    <div class="min-h-dvh lg:pl-64">
        <header class="sticky top-0 z-20 flex items-center justify-between gap-3 border-b border-app-border bg-app-bg/80 px-4 py-2 backdrop-blur-[12px] sm:px-6">
            <div class="flex items-center gap-2">
                <button type="button" x-on:click="$dispatch('open-modal', 'owner-menu')" aria-haspopup="dialog" aria-label="Open menu"
                    class="grid size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card focus-ring lg:hidden">
                    <x-ui.icon name="menu" />
                </button>
                <a href="{{ route('owner.dashboard') }}" class="inline-flex min-h-11 items-center rounded-control focus-ring lg:hidden" aria-label="Jhunlea Photography &amp; Printing, overview">
                    <x-ui.logo />
                </a>
            </div>

            <div class="flex items-center gap-2">
                <x-ui.theme-toggle />
                <x-layout.user-menu :user="auth()->user()" />
            </div>
        </header>

        <main id="main-content" tabindex="-1" class="px-4 py-8 focus:outline-none sm:px-6 sm:py-10">
            @yield('main')
        </main>
    </div>
@endsection
