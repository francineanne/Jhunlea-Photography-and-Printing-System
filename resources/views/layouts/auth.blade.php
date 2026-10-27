@extends('layouts.app')

{{-- Login family shell (design/login/customer_login): studio photo backdrop, glass panel, payment note. Pages fill the `auth` section. --}}
@section('content')
    <x-layout.skip-link />
    <x-ui.page-loader />
    <x-ui.toast />

    {{-- Backdrop: temporary Stitch placeholder photo under a scrim; replace with a real studio photo before going live --}}
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden bg-app-bg" aria-hidden="true">
        <div class="size-full scale-105 bg-cover bg-center" style="background-image: url('{{ asset('images/design-placeholders/auth-backdrop.jpg') }}')"></div>
        <div class="absolute inset-0 bg-gradient-to-t from-white via-white/85 to-white/90 dark:from-[#0e0e0e] dark:via-[#141414]/75 dark:to-[#0b0b0b]/90"></div>
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_center,transparent_0%,rgba(251,251,251,0.85)_100%)] dark:bg-[radial-gradient(circle_at_center,transparent_0%,rgba(10,10,10,0.85)_100%)]"></div>
    </div>

    <main id="main-content" tabindex="-1" class="mx-auto flex min-h-dvh w-full max-w-[512px] flex-col items-center justify-center px-4 py-6 focus:outline-none">
        <div class="mb-4 flex w-full items-center justify-between px-2">
            <span class="flex items-center gap-1 font-mono text-[12px] uppercase tracking-wider text-app-text-2">
                <x-ui.icon name="photo_camera" :size="15" class="text-brand dark:text-[#ffdad6]" />
                Customer portal
            </span>
            <x-ui.theme-toggle />
        </div>

        <section class="auth-panel relative flex w-full flex-col rounded-card p-6 backdrop-blur-xl md:p-8">
            <a href="{{ url('/') }}" class="group mx-auto mb-6 flex flex-col items-center rounded-control text-center focus-ring" aria-label="Jhunlea Photography &amp; Printing, home">
                <span class="mb-2 flex items-center justify-center gap-1">
                    <x-ui.aperture mark :size="28" :animate="false" class="transition-transform duration-500 group-hover:rotate-90" />
                    <span class="text-[22px] font-bold leading-[30px] tracking-[0.22em] text-app-text">JHUNLEA</span>
                </span>
                <span class="text-[11px] font-bold uppercase tracking-[0.28em] text-app-text-2">Photography &amp; Printing</span>
            </a>

            @yield('auth')
        </section>

        <p class="mt-6 flex items-center justify-center gap-1.5 px-4 text-center text-xs text-app-text-2">
            <x-ui.icon name="storefront" :size="15" class="shrink-0 text-brand dark:text-[#ffb4ac]" />
            Payment is made face-to-face at Jhunlea Photography &amp; Printing.
        </p>
    </main>
@endsection
