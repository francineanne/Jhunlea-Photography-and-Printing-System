@extends('layouts.app')

{{-- Customer shell. Pages fill the `main` section. Add a navigation item by adding one line here. --}}
@php
    // One entry = label, icon, route name
    $nav = [
        ['label' => 'Dashboard', 'icon' => 'dashboard', 'route' => 'customer.dashboard'],
    ];
@endphp

@section('content')
    <x-layout.skip-link />
    <x-ui.toast />

    <header class="sticky top-0 z-40 border-b border-app-border bg-app-bg/80 backdrop-blur-[12px]">
        <div class="mx-auto flex max-w-[1280px] items-center justify-between gap-3 px-4 py-2 sm:px-6">
            <div class="flex items-center gap-2 lg:gap-8">
                <button type="button" x-on:click="$dispatch('open-modal', 'customer-menu')" aria-haspopup="dialog" aria-label="Open menu"
                    class="grid size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card focus-ring lg:hidden">
                    <x-ui.icon name="menu" />
                </button>
                <a href="{{ route('customer.dashboard') }}" class="inline-flex min-h-11 items-center rounded-control focus-ring" aria-label="Jhunlea Photography &amp; Printing, my dashboard">
                    <x-ui.logo />
                </a>
                <x-layout.nav :items="$nav" variant="top" class="hidden lg:block" />
            </div>

            <div class="flex items-center gap-2">
                <x-ui.theme-toggle />
                <x-layout.user-menu :user="auth()->user()" />
            </div>
        </div>
    </header>

    <x-layout.drawer name="customer-menu" label="Menu">
        <x-slot:heading><x-ui.logo /></x-slot:heading>
        <x-layout.nav :items="$nav" label="Menu" />
    </x-layout.drawer>

    <main id="main-content" tabindex="-1" class="mx-auto max-w-[1280px] px-4 py-8 focus:outline-none sm:px-6 sm:py-10">
        @yield('main')
    </main>
@endsection
