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

    <header class="sticky top-0 z-40 bg-app-bg/90 shadow-[0_1px_8px_rgba(0,0,0,0.04)] backdrop-blur-xl">
        <div class="mx-auto flex h-16 max-w-[1280px] items-center justify-between gap-3 px-4 sm:h-20 sm:px-6">
            <div class="flex items-center gap-2 lg:gap-8">
                <button type="button" x-on:click="$dispatch('open-modal', 'customer-menu')" aria-haspopup="dialog" aria-label="Open menu"
                    class="grid size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card focus-ring lg:hidden">
                    <x-ui.icon name="menu" />
                </button>
                <a href="{{ route('customer.dashboard') }}" class="group inline-flex min-h-11 items-center rounded-control focus-ring" aria-label="Jhunlea Photography &amp; Printing, my dashboard">
                    <x-ui.logo mark compact />
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

    <footer class="mx-auto max-w-[1280px] px-4 pb-8 sm:px-6">
        <div class="flex items-center gap-4 rounded-card bg-neutral-100 p-4 dark:bg-white/5">
            <span class="grid size-10 shrink-0 place-items-center rounded-control bg-app-card text-brand" aria-hidden="true"><x-ui.icon name="storefront" :size="22" /></span>
            <div class="min-w-0 text-sm">
                <p class="font-semibold">Studio face-to-face transaction policy</p>
                <p class="text-app-text-2">Payment is made face-to-face at Jhunlea Photography &amp; Printing. No online checkout.</p>
            </div>
        </div>
        <p class="mt-4 text-xs text-app-text-2">&copy; {{ date('Y') }} Jhunlea Photography &amp; Printing. All rights reserved.</p>
    </footer>
@endsection
