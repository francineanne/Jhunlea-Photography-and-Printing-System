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
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body x-data class="min-h-screen">
    @yield('content')
</body>

</html>
