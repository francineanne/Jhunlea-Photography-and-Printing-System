@extends('layouts.auth')

@section('title', 'Forgot password')

@section('auth')
    <x-layout.auth-heading title="Forgot your password?">Enter your email address and we will send you a link to choose a new one.</x-layout.auth-heading>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Email address" name="email" type="email" icon="mail" :value="old('email')" autocomplete="email" required autofocus />

        <x-ui.button type="submit" class="group w-full shadow-[0_4px_14px_rgba(198,40,40,0.4)]">Send reset link <x-ui.icon name="arrow_forward" :size="18" class="transition-transform duration-200 group-hover:translate-x-1" /></x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center gap-1 font-semibold focus-ring text-brand underline-offset-4 hover:underline dark:text-[#ffb4ac] dark:hover:text-[#ffdad6] rounded-control">
            <x-ui.icon name="arrow_back" :size="18" /> Back to log in
        </a>
    </p>
@endsection
