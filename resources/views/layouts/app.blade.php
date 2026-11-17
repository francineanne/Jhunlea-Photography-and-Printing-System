<!DOCTYPE html>
@php
    // The appearance (Phase 6C): a signed-in person's choice is stored in users.theme and rendered here, so the first
    // paint is already right on every device. Guests keep theirs in the browser (localStorage). rescue(): this layout
    // also wraps the error pages, which must never throw
    $account = rescue(fn () => auth()->user(), null, false);
    $scheme = $account ? (rescue(fn () => $account->theme?->value, null, false) ?? 'system') : 'guest';
    $themeUrl = $account ? rescue(fn () => route($account->role === \App\Enums\UserRole::Owner ? 'owner.account.theme' : 'customer.account.theme'), null, false) : null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $scheme }}" @class(['dark' => $scheme === 'dark'])>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if ($themeUrl)
        <meta name="theme-save-url" content="{{ $themeUrl }}">
    @endif

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <title>@yield('title', config('app.name'))</title>
    @stack('head')

    <script>
        (function () {
            document.documentElement.classList.add('js');
            // light or dark: the signed-in person's saved choice; system: follow the device; guest: the browser's own choice
            var saved = document.documentElement.getAttribute('data-theme');
            var theme = saved === 'light' || saved === 'dark' ? saved : null;
            if (saved === 'guest') {
                try { theme = localStorage.getItem('theme'); } catch (e) {}
            }
            if (theme !== 'light' && theme !== 'dark') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.classList.toggle('dark', theme === 'dark');
            // Phase 10D: the owner's collapsed sidebar, applied before the first paint so nothing jumps
            try { if (localStorage.getItem('owner-sidebar') === 'collapsed') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}
        })();
    </script>

    {{-- Phase 10D: the text and icon fonts are fetched with the page, so nothing shifts when they arrive --}}
    @foreach (rescue(fn () => \App\Support\FontPreload::files(), [], false) as $font)
        <link rel="preload" href="{{ $font }}" as="font" type="font/woff2" crossorigin>
    @endforeach

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{--
        Phase 10D, instant navigation: the browser fetches a same-site page while the pointer rests on its link (or a
        finger touches it), so the click feels immediate. Only plain GET pages: never sign-out, downloads, CSV and
        printable pages, the email verification link, files, or a page whose opening changes something (a notification
        or a chat thread is marked read; a receipt or an end-of-day page is logged). Browsers without Speculation Rules
        ignore this block.
    --}}
    <script type="speculationrules">
        {"prefetch":[{"where":{"and":[
            {"href_matches":"/*"},
            {"not":{"href_matches":"/logout*"}},
            {"not":{"href_matches":"/email/verify/*"}},
            {"not":{"href_matches":"/reset-password/*"}},
            {"not":{"href_matches":"/files/*"}},
            {"not":{"href_matches":"/booking-*"}},
            {"not":{"href_matches":"/*/csv*"}},
            {"not":{"href_matches":"/*/receipt*"}},
            {"not":{"href_matches":"/*/end-of-day*"}},
            {"not":{"href_matches":"/*/notifications/*"}},
            {"not":{"href_matches":"/my/assistant*"}},
            {"not":{"href_matches":"/my/messages/*"}},
            {"not":{"href_matches":"/owner/messages/*"}},
            {"not":{"selector_matches":"[data-no-prefetch], [download], [target=_blank], [rel~=external]"}}
        ]},"eagerness":"moderate"}]}
    </script>
</head>

<body x-data class="min-h-screen">
    @yield('content')
</body>

</html>
