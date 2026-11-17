@extends('layouts.auth')

@section('title', $state === 'invalid' ? 'Link not valid' : 'Email verified')

{{--
    Phase 10E: what an email verification link shows when it cannot simply sign the person in.
    invalid: expired, tampered or unknown. other: the link verified another account than the one signed in on this
    device (the signed-in account is kept).
--}}
@section('auth')
    @if ($state === 'invalid')
        <x-layout.auth-heading title="This link has expired">The verification link is older than {{ (int) config('auth.verification.expire', 60) }} minutes, was changed, or belongs to an account that no longer needs it. Ask for a new one: it comes by email within a minute.</x-layout.auth-heading>

        @if ($signedIn)
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-ui.button type="submit" class="w-full"><x-ui.icon name="forward_to_inbox" :size="18" />Send a new link</x-ui.button>
            </form>
        @else
            <x-ui.button :href="route('login')" class="w-full"><x-ui.icon name="login" :size="18" />Log in to send a new link</x-ui.button>
            <p class="mt-3 text-sm text-app-text-2">After you log in, the "Check your email" page has a button to send a new link.</p>
        @endif
    @else
        <x-layout.auth-heading title="Email verified">Email verified for <strong class="break-all">{{ $masked }}</strong>. Sign in with that account to continue.</x-layout.auth-heading>
        <p class="text-sm text-app-text-2">This device is signed in to a different account, so it stays signed in as before.</p>
        <div class="mt-5 flex flex-col gap-2 sm:flex-row">
            <x-ui.button :href="url('/')" variant="outline" class="w-full sm:w-auto"><x-ui.icon name="home" :size="18" />Go to the home page</x-ui.button>
            <form method="POST" action="{{ route('logout') }}" class="w-full sm:w-auto">
                @csrf
                <x-ui.button type="submit" class="w-full"><x-ui.icon name="logout" :size="18" />Sign out to switch accounts</x-ui.button>
            </form>
        </div>
    @endif
@endsection
