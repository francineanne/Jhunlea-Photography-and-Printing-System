@extends('layouts.app')

{{-- Public page shell. Pages fill the `main` section. Add a header link by adding one line here. --}}
@php
    // One entry = label, icon, route name. Empty for now: no public page besides the home page exists yet.
    $links = [];

    // rescue(): this layout also wraps the error pages, which must never throw (session store down, odd role value)
    $home = rescue(fn () => auth()->user()?->role->home(), null, false);
@endphp

@section('content')
    <x-layout.skip-link />
    <x-ui.page-loader />
    <x-ui.toast />

    <div class="flex min-h-dvh flex-col">
    <header class="sticky top-0 z-40 border-b border-app-border bg-app-bg/80 backdrop-blur-[12px]">
        <div class="mx-auto flex max-w-[1280px] items-center justify-between gap-3 px-4 py-2 sm:px-6">
            <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center rounded-control focus-ring" aria-label="Jhunlea Photography &amp; Printing, home">
                <x-ui.logo />
            </a>

            <div class="flex items-center gap-2">
                @if (count($links))
                    <x-layout.nav :items="$links" variant="top" class="hidden md:block" />
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
                    class="grid size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card focus-ring sm:hidden">
                    <x-ui.icon name="menu" />
                </button>
            </div>
        </div>
    </header>

    <x-layout.drawer name="public-menu" label="Menu" side="right" :close-at="640">
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

    <footer class="border-t border-app-border">
        <div class="mx-auto flex max-w-[1280px] flex-col gap-4 px-4 py-8 sm:px-6 md:flex-row md:items-center md:justify-between">
            <x-ui.logo />
            <div class="space-y-1 text-sm text-app-text-2 md:text-right">
                <p>Payment is made face-to-face at Jhunlea Photography &amp; Printing. No online checkout.</p>
                <p>&copy; {{ date('Y') }} Jhunlea Photography &amp; Printing</p>
            </div>
        </div>
    </footer>
    </div>
@endsection
