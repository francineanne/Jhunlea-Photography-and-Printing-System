<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <title>@yield('title', config('app.name'))</title>
    @stack('head')

    <script>
        (function () {
            document.documentElement.classList.add('js');
            var theme = null;
            try { theme = localStorage.getItem('theme'); } catch (e) {}
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
