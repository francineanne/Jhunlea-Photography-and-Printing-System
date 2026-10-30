@extends('layouts.app')

{{-- Public page shell. Pages fill the `main` section. Add a header link by adding one line here. --}}
@php
    // One entry = label, icon, route name (and the route names that keep it highlighted). Only pages that exist.
    $links = [
        ['label' => 'Printing services', 'icon' => 'print', 'route' => 'services.index'],
        ['label' => 'Photography packages', 'icon' => 'photo_camera', 'route' => 'packages.index', 'match' => ['packages.index', 'packages.show', 'packages.compare']],
        ['label' => 'Package finder', 'icon' => 'auto_awesome', 'route' => 'packages.finder'],
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

    <div class="flex min-h-dvh flex-col">
    {{-- Studio policy strip above the header, as in the landing design --}}
    <div class="bg-neutral-100 px-4 py-1.5 dark:bg-white/5">
        <p class="mx-auto flex max-w-[1280px] items-center justify-center gap-1.5 text-center text-[11px] font-bold uppercase leading-4 tracking-wider text-app-text-2">
            <x-ui.icon name="storefront" :size="15" class="shrink-0 text-brand" />
            Studio policy: payment is made face-to-face at Jhunlea Photography &amp; Printing. No online checkout.
        </p>
    </div>

    <header class="sticky top-0 z-40 border-b border-app-border bg-app-bg/90 shadow-[0_1px_8px_rgba(0,0,0,0.03)] backdrop-blur-xl">
        <div class="mx-auto flex h-16 max-w-[1280px] items-center justify-between gap-3 px-4 sm:h-20 sm:px-6">
            <a href="{{ url('/') }}" class="group inline-flex min-h-11 items-center rounded-control focus-ring" aria-label="Jhunlea Photography &amp; Printing, home">
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

    <footer class="border-t border-app-border bg-neutral-100 dark:bg-white/5">
        <div @class(['mx-auto flex max-w-[1280px] flex-col gap-4 px-4 pt-8 sm:px-6 md:flex-row md:items-center md:justify-between', 'pb-24' => $showChat, 'pb-8' => ! $showChat])>
            <div class="space-y-3">
                <x-ui.logo />
                <nav aria-label="Footer">
                    <ul class="flex flex-wrap gap-x-5 gap-y-1 text-sm font-semibold">
                        @foreach ($links as $link)
                            <li><a href="{{ route($link['route']) }}" class="inline-flex min-h-11 items-center rounded text-app-text-2 hover:text-brand focus-ring">{{ $link['label'] }}</a></li>
                        @endforeach
                        @if ($hasFaq ?? false)
                            <li><a href="{{ route('faq') }}" class="inline-flex min-h-11 items-center rounded text-app-text-2 hover:text-brand focus-ring">FAQ</a></li>
                        @endif
                    </ul>
                </nav>
            </div>
            {{-- Only the details the owner filled in on the Settings page; nothing at all when none is set --}}
            @php($shop ??= [])
            @if (filled($shop['address'] ?? null) || filled($shop['phone'] ?? null) || filled($shop['email'] ?? null))
                <address class="min-w-0 space-y-1 text-sm not-italic text-app-text-2 md:max-w-xs">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-app-text">Visit or contact{{ filled($shop['shop_name'] ?? null) ? ' '.$shop['shop_name'] : '' }}</p>
                    @if (filled($shop['address'] ?? null))
                        <p class="flex items-start gap-1.5 whitespace-pre-line break-words"><x-ui.icon name="location_on" :size="16" class="mt-0.5 shrink-0 text-brand" /><span class="min-w-0">{{ $shop['address'] }}</span></p>
                    @endif
                    @if (filled($shop['phone'] ?? null))
                        <p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $shop['phone']) }}" class="inline-flex min-h-11 items-center gap-1.5 rounded hover:text-brand focus-ring"><x-ui.icon name="call" :size="16" class="shrink-0 text-brand" />{{ $shop['phone'] }}</a></p>
                    @endif
                    @if (filled($shop['email'] ?? null))
                        <p class="min-w-0"><a href="mailto:{{ $shop['email'] }}" class="inline-flex min-h-11 min-w-0 max-w-full items-center gap-1.5 break-all rounded hover:text-brand focus-ring"><x-ui.icon name="mail" :size="16" class="shrink-0 text-brand" /><span class="min-w-0">{{ $shop['email'] }}</span></a></p>
                    @endif
                </address>
            @endif
            <div class="space-y-1 text-sm text-app-text-2 md:text-right">
                <p>Payment is made face-to-face at Jhunlea Photography &amp; Printing. No online checkout.</p>
                <p>&copy; {{ date('Y') }} Jhunlea Photography &amp; Printing</p>
            </div>
        </div>
    </footer>
    </div>

    @if ($showChat)
        <x-chat.window mode="compact" />
    @endif
@endsection
