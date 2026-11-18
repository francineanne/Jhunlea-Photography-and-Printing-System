@extends('layouts.app')

{{-- Public page shell. Pages fill the `main` section. Add a header link by adding one line here. --}}
@php
    // One entry = label, icon, route name (and the route names that keep it highlighted). Only pages that exist.
    $links = [
        ['label' => 'Printing services', 'short' => 'Printing', 'icon' => 'print', 'route' => 'services.index'],
        ['label' => 'Photography packages', 'short' => 'Packages', 'icon' => 'photo_camera', 'route' => 'packages.index', 'match' => ['packages.index', 'packages.show', 'packages.compare']],
        ['label' => 'Package finder', 'short' => 'Finder', 'icon' => 'travel_explore', 'route' => 'packages.finder'],
    ];

    // rescue(): this layout also wraps the error pages, which must never throw (session store down, odd role value)
    $home = rescue(fn () => auth()->user()?->role->home(), null, false);

    // The floating chat, for guests and customers; never on the error pages, which must stay as simple as possible
    $showChat = ! ($isErrorPage ?? false) && rescue(fn () => auth()->user()?->role !== \App\Enums\UserRole::Owner, false, false);
@endphp

@section('content')
    <x-layout.skip-link />
    <x-ui.page-loader />
    <x-ui.toast />
    <x-ui.confirm />
    <x-ui.lightbox />

    <div class="flex min-h-dvh flex-col">
    {{-- Phase 10D: ONE short, current payment line (from the payment rules), always one line: it truncates on phones
         and "Details" opens the FAQ (or the terms when no question is published) --}}
    @unless ($isErrorPage ?? false)
        <div class="bg-neutral-100 px-4 dark:bg-white/5" data-policy-bar>
            <p class="mx-auto flex max-w-[1280px] items-center justify-center gap-1.5 text-xs font-semibold text-app-text-2">
                <x-ui.icon name="storefront" :size="15" class="shrink-0 text-brand" />
                <span class="min-w-0 truncate" title="{{ $policyLine ?? '' }}">{{ $policyLine ?? 'No online payment. Pay at the shop in cash or by GCash.' }}</span>
                <a href="{{ ($hasFaq ?? false) ? route('faq') : route('terms') }}" class="inline-flex min-h-11 shrink-0 items-center rounded px-1 font-bold text-brand underline-offset-2 hover:underline focus-ring sm:min-h-8">Details<span class="sr-only"> about paying</span></a>
            </p>
        </div>
    @endunless

    <header class="site-header sticky top-0 z-40 border-b border-app-border bg-app-bg/90 shadow-[0_1px_8px_rgba(0,0,0,0.03)] backdrop-blur-xl">
        <div class="mx-auto flex h-16 max-w-[1280px] items-center justify-between gap-4 px-4 sm:h-20 sm:px-6 lg:gap-8">
            {{-- Phase 10F: on the landing page itself the logo replays the splash once instead of reloading the page --}}
            <a href="{{ url('/') }}" class="group inline-flex min-h-11 shrink-0 items-center rounded-control focus-ring" aria-label="Jhunlea Photography &amp; Printing, home"
                @if (request()->is('/')) x-on:click.prevent="$dispatch('replay-loader')" data-logo-replay @endif>
                <x-ui.logo mark compact />
            </a>

            <div class="flex items-center gap-2">
                @if (count($links))
                    <x-layout.nav :items="$links" variant="top" class="hidden lg:block" />
                @endif

                <x-ui.theme-toggle />

                <div class="hidden items-center gap-2 sm:flex">
                    @if ($home)
                        <x-ui.button :href="$home" variant="outline">Dashboard</x-ui.button>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-ui.button type="submit" variant="ghost">Log out</x-ui.button>
                        </form>
                    @else
                        <x-ui.button :href="route('login')" variant="ghost">Log in</x-ui.button>
                        <x-ui.button :href="route('register')">Register</x-ui.button>
                    @endif
                </div>

                <button type="button" x-on:click="$dispatch('open-modal', 'public-menu')" aria-haspopup="dialog" aria-label="Open menu"
                    class="grid size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card focus-ring lg:hidden">
                    <x-ui.icon name="menu" />
                </button>
            </div>
        </div>
    </header>

    <x-layout.drawer name="public-menu" label="Menu" side="right" :close-at="1024">
        <x-slot:heading><x-ui.logo /></x-slot:heading>

        @if (count($links))
            <x-layout.nav :items="$links" label="Menu" class="mb-4" />
        @endif

        <div class="space-y-2">
            @if ($home)
                <x-ui.button :href="$home" variant="outline" class="w-full">Dashboard</x-ui.button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" class="w-full">Log out</x-ui.button>
                </form>
            @else
                <x-ui.button :href="route('login')" variant="outline" class="w-full">Log in</x-ui.button>
                <x-ui.button :href="route('register')" class="w-full">Register</x-ui.button>
            @endif
        </div>
    </x-layout.drawer>

    <main id="main-content" tabindex="-1" class="flex-1 focus:outline-none">
        @yield('main')
    </main>

    {{-- Phase 10G: the one site footer; the payment line is in the top bar here, so the footer leaves it out (error pages
         stay as simple as possible and show neither) --}}
    <x-layout.site-footer :shop="$shop ?? []" :links="$links" :has-faq="$hasFaq ?? false" :bottom-room="$showChat" />
    </div>

    @if ($showChat)
        <x-chat.window mode="compact" />
    @endif
@endsection
