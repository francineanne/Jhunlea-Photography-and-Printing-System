@extends('layouts.public')

@section('title', config('app.name'))

@section('main')
    @php $home = rescue(fn () => auth()->user()?->role->home(), null, false); @endphp
    <section class="mx-auto flex max-w-2xl flex-col items-center px-4 py-20 text-center sm:py-28">
        <x-ui.logo size="lg" />
        <p class="mt-8 font-display text-2xl font-semibold sm:text-3xl">Our full website is coming soon.</p>
        <p class="mt-3 text-app-text-2">You can already create an account or log in.</p>

        <div class="mt-8 flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
            @if ($home)
                <x-ui.button :href="$home">Go to my dashboard</x-ui.button>
            @else
                <x-ui.button :href="route('login')" variant="outline">Log in</x-ui.button>
                <x-ui.button :href="route('register')">Register</x-ui.button>
            @endif
        </div>
    </section>
@endsection
