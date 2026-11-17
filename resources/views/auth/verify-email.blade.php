@extends('layouts.auth')

@section('title', 'Check your email')

{{--
    Phase 10C: a signed-in customer whose email is not verified yet lands here. Until they verify, only this page, the
    account page and signing out are open to them (the `verified` middleware on every customer feature route).
--}}
@section('auth')
    <x-layout.auth-heading title="Check your email">We sent a link to <strong class="break-all">{{ $email }}</strong>. Open it to verify your email and start using your account. The link works for {{ (int) config('auth.verification.expire', 60) }} minutes.</x-layout.auth-heading>

    {{-- Phase 10E: verified on another device (a phone)? This page notices within a few seconds and moves on --}}
    <div x-data="verifyWatch(@js(route('verification.status')), @js(route('verification.notice', ['verified' => 1])))" hidden></div>
    <p class="mb-4 flex items-center gap-2 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5" role="status">
        <x-ui.icon name="devices" :size="18" class="shrink-0 text-brand" />You can open the link on this device or on your phone. This page moves on by itself once you are verified.
    </p>

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-ui.button type="submit" class="group w-full shadow-[0_4px_14px_rgba(198,40,40,0.4)]"><x-ui.icon name="forward_to_inbox" :size="18" />Send the link again</x-ui.button>
    </form>

    <p class="mt-4 text-sm text-app-text-2">Not in your inbox? Check the spam or promotions folder. Typed the wrong email?</p>

    <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-between">
        <a href="{{ route(auth()->user()->role->value.'.account.show') }}" class="inline-flex min-h-11 items-center gap-1 rounded-control font-semibold text-brand underline-offset-4 hover:underline focus-ring dark:text-[#ffb4ac]">
            <x-ui.icon name="edit" :size="18" />Change my email
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="inline-flex min-h-11 items-center gap-1 rounded-control px-1 font-semibold text-app-text-2 hover:text-brand focus-ring">
                <x-ui.icon name="logout" :size="18" />Sign out
            </button>
        </form>
    </div>
@endsection
