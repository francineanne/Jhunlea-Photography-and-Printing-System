@extends('layouts.auth')

@section('title', 'Create account')

@section('auth')
    <x-layout.auth-heading title="Create your account">Sign up to send printing requests and book photoshoots.</x-layout.auth-heading>

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Full name" name="name" icon="person" :value="old('name')" autocomplete="name" required autofocus />
        <x-ui.input label="Email address" name="email" type="email" icon="mail" data-live-check="email" :value="old('email')" autocomplete="email" required />
        <x-ui.input label="Mobile number" name="phone" type="tel" icon="call" data-live-check="mobile" :value="old('phone')" autocomplete="tel" inputmode="tel" maxlength="{{ \App\Support\PhilippineMobile::MAX_TYPED }}" required
            placeholder="0917 123 4567" hint="A Philippine mobile number, so the studio can reach you about your requests." />

        <div class="space-y-4" x-data="passwordStrength(@js(\App\Support\PasswordRules::all()))">
            <div>
                <x-ui.input label="Password" name="password" type="password" icon="lock" autocomplete="new-password" required
                    :hint="\App\Support\PasswordRules::summary()" />
                <x-ui.password-feedback mode="meter" />
            </div>
            <div>
                <x-ui.input label="Confirm password" name="password_confirmation" type="password" icon="lock" autocomplete="new-password" required />
                <x-ui.password-feedback mode="match" />
            </div>
        </div>

        {{-- Phase 10C: consent is required; the box is never ticked for the customer --}}
        <x-ui.checkbox name="terms" value="1" :checked="(bool) old('terms')" required>
            I agree to the shop's <a href="{{ route('terms') }}" target="_blank" rel="noopener" class="font-semibold text-brand underline underline-offset-4 focus-ring rounded dark:text-[#ffb4ac]">terms</a> and the processing of my data under the Data Privacy Act of 2012 (RA 10173).
        </x-ui.checkbox>

        <x-ui.button type="submit" class="group w-full shadow-[0_4px_14px_rgba(198,40,40,0.4)]">Create account <x-ui.icon name="arrow_forward" :size="18" class="transition-transform duration-200 group-hover:translate-x-1" /></x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-app-text-2">
        Already have an account?
        <a href="{{ route('login') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center font-semibold focus-ring text-brand underline-offset-4 hover:underline dark:text-[#ffb4ac] dark:hover:text-[#ffdad6] rounded-control">Log in</a>
    </p>
@endsection
