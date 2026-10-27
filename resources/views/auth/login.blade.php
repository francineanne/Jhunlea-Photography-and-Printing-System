@extends('layouts.auth')

@section('title', 'Log in')

@section('auth')
    <h1 class="font-display text-3xl font-semibold">Welcome back</h1>
    <p class="mt-2 text-sm text-app-text-2">Log in to follow your printing requests and photoshoot bookings.</p>

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
        @csrf

        <x-ui.input label="Email address" name="email" type="email" icon="mail" :value="old('email')" autocomplete="email" required autofocus />
        <x-ui.input label="Password" name="password" type="password" icon="lock" autocomplete="current-password" required />

        <div class="flex flex-wrap items-center justify-between gap-x-4">
            <x-ui.checkbox name="remember" label="Remember me" value="1" :checked="old('remember')" />
            <a href="{{ route('password.request') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-brand hover:underline focus-ring rounded-control">Forgot password?</a>
        </div>

        <x-ui.button type="submit" class="w-full">Log in <x-ui.icon name="arrow_forward" :size="18" /></x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-app-text-2">
        New here?
        <a href="{{ route('register') }}" class="inline-flex min-h-11 items-center font-semibold text-brand hover:underline focus-ring rounded-control">Create an account</a>
    </p>
@endsection
