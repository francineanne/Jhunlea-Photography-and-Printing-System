@extends('layouts.auth')

@section('title', 'Reset password')

@section('auth')
    <x-layout.auth-heading title="Choose a new password">Enter a new password for your account.</x-layout.auth-heading>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-ui.input label="Email address" name="email" type="email" icon="mail" :value="old('email', $email)" autocomplete="email" required />

        <div class="space-y-4" x-data="passwordStrength(@js(\App\Support\PasswordRules::all()))">
            <div>
                <x-ui.input label="New password" name="password" type="password" icon="lock" autocomplete="new-password" required autofocus />
                <x-ui.password-feedback mode="checklist" />
            </div>
            <div>
                <x-ui.input label="Confirm new password" name="password_confirmation" type="password" icon="lock" autocomplete="new-password" required />
                <x-ui.password-feedback mode="match" />
            </div>
        </div>

        <x-ui.button type="submit" class="group w-full shadow-[0_4px_14px_rgba(198,40,40,0.4)]">Reset password <x-ui.icon name="arrow_forward" :size="18" class="transition-transform duration-200 group-hover:translate-x-1" /></x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center gap-1 font-semibold focus-ring text-brand underline-offset-4 hover:underline dark:text-[#ffb4ac] dark:hover:text-[#ffdad6] rounded-control">
            <x-ui.icon name="arrow_back" :size="18" /> Back to log in
        </a>
    </p>
@endsection
