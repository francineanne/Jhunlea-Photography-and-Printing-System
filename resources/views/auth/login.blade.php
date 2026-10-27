@extends('layouts.auth')

@section('title', 'Log in')

@section('auth')
    <x-layout.auth-heading title="Welcome back" center>Log in to follow your printing requests and photoshoot bookings.</x-layout.auth-heading>

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Email address" name="email" type="email" icon="mail" :value="old('email')" autocomplete="email" required autofocus />
        <x-ui.input label="Password" name="password" type="password" icon="lock" autocomplete="current-password" required />

        <div class="flex flex-wrap items-center justify-between gap-x-4">
            <x-ui.checkbox name="remember" label="Remember me" value="1" :checked="old('remember')" />
            <a href="{{ route('password.request') }}" class="inline-flex min-h-11 items-center rounded-control text-sm font-semibold text-brand underline-offset-4 hover:underline focus-ring dark:text-[#ffb4ac] dark:hover:text-[#ffdad6]">Forgot password?</a>
        </div>

        <x-ui.button type="submit" class="group w-full shadow-[0_4px_14px_rgba(198,40,40,0.4)]">Log in <x-ui.icon name="arrow_forward" :size="18" class="transition-transform duration-200 group-hover:translate-x-1" /></x-ui.button>
    </form>

    <p class="mt-6 rounded-b-lg bg-gradient-to-t from-black/5 to-transparent pt-4 text-center text-sm text-app-text-2 dark:from-white/5">
        New to Jhunlea?
        <a href="{{ route('register') }}" class="inline-flex min-h-11 items-center font-semibold text-brand underline-offset-4 hover:underline focus-ring rounded-control dark:text-[#ffb4ac] dark:hover:text-[#ffdad6]">Create an account</a>
    </p>
@endsection
