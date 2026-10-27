@extends('layouts.auth')

@section('title', 'Reset password')

@section('auth')
    <h1 class="font-display text-3xl font-semibold">Choose a new password</h1>
    <p class="mt-2 text-sm text-app-text-2">Enter a new password for your account.</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-ui.input label="Email address" name="email" type="email" icon="mail" :value="old('email', $email)" autocomplete="email" required />
        <x-ui.input label="New password" name="password" type="password" icon="lock" autocomplete="new-password" required autofocus
            hint="At least 8 characters, with upper and lower case letters and a number." />
        <x-ui.input label="Confirm new password" name="password_confirmation" type="password" icon="lock" autocomplete="new-password" required />

        <x-ui.button type="submit" class="w-full">Reset password <x-ui.icon name="arrow_forward" :size="18" /></x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center gap-1 font-semibold text-brand hover:underline focus-ring rounded-control">
            <x-ui.icon name="arrow_back" :size="18" /> Back to log in
        </a>
    </p>
@endsection
