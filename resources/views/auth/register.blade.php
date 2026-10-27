@extends('layouts.auth')

@section('title', 'Create account')

@section('auth')
    <h1 class="font-display text-3xl font-semibold">Create your account</h1>
    <p class="mt-2 text-sm text-app-text-2">Sign up to send printing requests and book photoshoots.</p>

    <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-5">
        @csrf

        <x-ui.input label="Full name" name="name" icon="person" :value="old('name')" autocomplete="name" required autofocus />
        <x-ui.input label="Email address" name="email" type="email" icon="mail" :value="old('email')" autocomplete="email" required />
        <x-ui.input label="Phone number (optional)" name="phone" type="tel" icon="call" :value="old('phone')" autocomplete="tel" hint="So we can reach you about your requests." />
        <x-ui.input label="Password" name="password" type="password" icon="lock" autocomplete="new-password" required
            hint="At least 8 characters, with upper and lower case letters and a number." />
        <x-ui.input label="Confirm password" name="password_confirmation" type="password" icon="lock" autocomplete="new-password" required />

        <x-ui.notice variant="payment" />

        <x-ui.button type="submit" class="w-full">Create account <x-ui.icon name="arrow_forward" :size="18" /></x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-app-text-2">
        Already have an account?
        <a href="{{ route('login') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center font-semibold text-brand hover:underline focus-ring rounded-control">Log in</a>
    </p>
@endsection
