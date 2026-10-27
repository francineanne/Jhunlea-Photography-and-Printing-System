@props(['code', 'title', 'message'])

{{-- Used by the 403, 404 and 419 pages. The button goes to the signed-in user's own home, or to the public home for guests. --}}
{{-- rescue(): an error page must never throw itself (session store down, odd role value), so it falls back to the guest view --}}
@php
    $userHome = rescue(fn () => auth()->user()?->role->home(), null, false);
    $signedIn = $userHome !== null;
    $home = $userHome ?? url('/');
@endphp

<div class="mx-auto flex max-w-xl flex-col items-center px-4 py-16 text-center sm:py-24">
    <p class="font-display text-7xl font-semibold text-brand sm:text-8xl" aria-hidden="true">{{ $code }}</p>
    <h1 class="mt-4 font-display text-3xl font-semibold sm:text-4xl">{{ $title }}</h1>
    <p class="mt-3 text-app-text-2">{{ $message }}</p>
    <x-ui.button :href="$home" class="mt-8">
        <x-ui.icon :name="$signedIn ? 'dashboard' : 'home'" :size="20" />
        {{ $signedIn ? 'Go to my dashboard' : 'Go back home' }}
    </x-ui.button>
</div>
