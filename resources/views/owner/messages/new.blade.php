@extends('layouts.owner')

@section('title', 'New message | '.config('app.name'))

{{--
    Phase 10E: the owner starts a conversation. A plain GET search (works without JavaScript and with the keyboard), then
    one "Message" button per customer that opens or creates their Studio thread. Walk-ins never appear: they have no
    account to read a message.
--}}
@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('owner.messages.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Messages</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">New message</li>
        </ol>
    </nav>

    <div class="max-w-3xl">
        <h1 class="font-display text-3xl font-semibold tracking-tight sm:text-4xl">New message</h1>
        <p class="mt-2 text-app-text-2">Find a customer with an account by name, email or mobile number. Their conversation with the studio opens, so you can write first.</p>

        <form method="GET" action="{{ route('owner.messages.new') }}" role="search" class="mt-6 flex flex-col gap-2 sm:flex-row sm:items-end">
            <div class="min-w-0 flex-1">
                <x-ui.input label="Search customers" name="q" type="search" maxlength="80" :value="$search" autofocus autocomplete="off" placeholder="For example: Ana, ana@, 0917" />
            </div>
            <x-ui.button type="submit"><x-ui.icon name="search" :size="18" />Search</x-ui.button>
        </form>

        <div class="mt-6" role="status">
            @if ($search === '')
                <p class="text-sm text-app-text-2">Type at least part of a name, an email or a mobile number.</p>
            @elseif ($customers->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state icon="person_search" :level="2" title="No customer with an account matches" description="Walk-in customers have no account to message; call them on the phone number on their customer page." />
                </x-ui.card>
            @else
                <p class="mb-3 text-sm text-app-text-2">{{ $customers->count() }} {{ Str::plural('customer', $customers->count()) }}{{ $customers->count() >= $limit ? ' (the first '.$limit.'; type more to narrow it)' : '' }}</p>
                <ul class="divide-y divide-app-border rounded-card border border-app-border bg-app-card">
                    @foreach ($customers as $customer)
                        <li class="flex flex-wrap items-center gap-3 p-3 sm:flex-nowrap">
                            <x-ui.user-avatar :user="$customer" size="md" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold" title="{{ $customer->name }}">{{ $customer->name }}</p>
                                <p class="truncate text-sm text-app-text-2" title="{{ $customer->email }}">{{ $customer->email }}</p>
                                @unless ($customer->hasVerifiedEmail())
                                    <p class="mt-0.5 text-xs text-status-amber">Email not verified yet: they will read it after verifying.</p>
                                @endunless
                            </div>
                            <form method="POST" action="{{ route('owner.messages.start') }}" class="w-full sm:w-auto">
                                @csrf
                                <input type="hidden" name="customer" value="{{ $customer->id }}">
                                <x-ui.button type="submit" class="w-full"><x-ui.icon name="forum" :size="18" />Message<span class="sr-only"> {{ $customer->name }}</span></x-ui.button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
