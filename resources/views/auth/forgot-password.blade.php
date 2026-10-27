@extends('layouts.auth')

@section('title', 'Forgot password')

@section('auth')
    <h1 class="font-display text-3xl font-semibold">Forgot your password?</h1>
    <p class="mt-2 text-sm text-app-text-2">Enter your email address and we will send you a link to choose a new one.</p>

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
        @csrf

        <x-ui.input label="Email address" name="email" type="email" icon="mail" :value="old('email')" autocomplete="email" required autofocus />

        <x-ui.button type="submit" class="w-full">Send reset link <x-ui.icon name="arrow_forward" :size="18" /></x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center gap-1 font-semibold text-brand hover:underline focus-ring rounded-control">
            <x-ui.icon name="arrow_back" :size="18" /> Back to log in
        </a>
    </p>
@endsection
