@extends('layouts.owner')

@section('title', 'Walk-in')

{{--
    Phase 10J: the ONE walk-in area. Walk-in customers have no account and are never notified, so this page is where
    the owner starts their orders and bookings, sees what needs a call (deadlines, balances, pickups) and finds them.
    Walk-in records follow the same rules as online ones; nothing here decides anything.
--}}
@php
    use App\Support\LocalTime;
    use App\Support\Money;
    use App\Support\PhilippineMobile;
    use App\Services\WalkIn\WalkInDesk;

    $call = fn (?string $phone) => $phone ? ['href' => PhilippineMobile::telHref($phone), 'text' => PhilippineMobile::display($phone)] : null;
    $total = $attention['awaiting']->count() + $attention['balance']->count() + $attention['ready']->count() + $attention['prepay']->count();
@endphp

@section('main')
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-2xl">
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Counter</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Walk-in</h1>
            <p class="mt-2 text-app-text-2">Customers served at the shop, without an account. Their orders and bookings follow the same rules as online ones. They get no notifications, so call them when something is due.</p>
        </div>
    </div>

    {{-- a) Start a walk-in, or find one --}}
    <section class="mt-6 grid gap-3 sm:grid-cols-2" aria-label="Start a walk-in">
        <a href="{{ route('owner.orders.walk-in.create') }}" class="group flex min-h-20 items-center gap-4 rounded-card border border-app-border bg-app-card p-4 shadow-sm transition-colors hover:border-brand focus-ring dark:shadow-none" data-walk-in-start="order">
            <span class="grid size-12 shrink-0 place-items-center rounded-control bg-brand text-white" aria-hidden="true"><x-ui.icon name="print" :size="24" /></span>
            <span class="min-w-0"><span class="block font-semibold group-hover:text-brand">New walk-in printing order</span><span class="block text-sm text-app-text-2">Accepted at the price you set; pay now or later.</span></span>
        </a>
        <a href="{{ route('owner.bookings.walk-in.create') }}" class="group flex min-h-20 items-center gap-4 rounded-card border border-app-border bg-app-card p-4 shadow-sm transition-colors hover:border-brand focus-ring dark:shadow-none" data-walk-in-start="booking">
            <span class="grid size-12 shrink-0 place-items-center rounded-control bg-brand text-white" aria-hidden="true"><x-ui.icon name="photo_camera" :size="24" /></span>
            <span class="min-w-0"><span class="block font-semibold group-hover:text-brand">New walk-in booking</span><span class="block text-sm text-app-text-2">The same times and downpayment rules as online.</span></span>
        </a>
    </section>

    <form method="GET" action="{{ route('owner.walk-in.index') }}" role="search" class="mt-4 max-w-xl">
        <x-ui.typeahead :url="route('owner.suggest')" scope="walk_in" label="Find a walk-in customer" :value="$search" placeholder="Name or mobile number" />
    </form>

    {{-- b) Needs attention --}}
    <section class="mt-8" aria-labelledby="attention-title">
        <h2 id="attention-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="notifications_active" :size="22" class="text-brand" />Needs attention
            <span class="rounded-full bg-neutral-100 px-2 py-0.5 font-mono text-xs font-semibold text-app-text-2 dark:bg-white/10">{{ $total }}</span></h2>

        @if ($total === 0)
            <x-ui.empty-state icon="task_alt" title="Nothing needs a call right now" description="Deadlines, balances and pickups of walk-in customers show here." class="mt-4" />
        @else
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                @if ($attention['awaiting']->isNotEmpty())
                    <x-ui.card class="!p-4 sm:!p-5" data-attention="awaiting">
                        <h3 class="font-semibold">Bookings awaiting their downpayment</h3>
                        <p class="text-xs text-app-text-2">Cancelled by the system at the deadline when not paid. Record it in Payments.</p>
                        <ul class="mt-3 divide-y divide-app-border">
                            @foreach ($attention['awaiting'] as $booking)
                                <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2.5">
                                    <span class="min-w-0">
                                        <a href="{{ route('owner.bookings.show', $booking) }}" class="inline-flex min-h-11 items-center font-mono text-sm font-semibold hover:text-brand focus-ring sm:min-h-0">{{ $booking->reference_number }}</a>
                                        <span class="text-sm"> · <a href="{{ route('owner.customers.show', $booking->customer_id) }}" class="hover:underline focus-ring">{{ $booking->customer?->name }}</a></span>
                                        <span class="block text-xs text-app-text-2">Shoot {{ LocalTime::format($booking->starts_at) }} · due by {{ LocalTime::format($booking->downpayment_due_at) }}</span>
                                        <x-ui.countdown :until="$booking->downpayment_due_at" class="text-xs" />
                                    </span>
                                    @if ($phone = $call($booking->contact_number ?? $booking->customer?->phone))
                                        <a href="{{ $phone['href'] }}" class="inline-flex min-h-11 items-center gap-1 rounded-control px-2 font-mono text-sm font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac]"><x-ui.icon name="call" :size="16" />{{ $phone['text'] }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endif

                @if ($attention['balance']->isNotEmpty())
                    <x-ui.card class="!p-4 sm:!p-5" data-attention="balance">
                        <h3 class="font-semibold">Shoots in the next 7 days with a balance due</h3>
                        <ul class="mt-3 divide-y divide-app-border">
                            @foreach ($attention['balance'] as $booking)
                                <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2.5">
                                    <span class="min-w-0">
                                        <a href="{{ route('owner.bookings.show', $booking) }}" class="inline-flex min-h-11 items-center font-mono text-sm font-semibold hover:text-brand focus-ring sm:min-h-0">{{ $booking->reference_number }}</a>
                                        <span class="text-sm"> · {{ $booking->customer?->name }}</span>
                                        <span class="block text-xs text-app-text-2">{{ LocalTime::format($booking->starts_at) }} · {{ Money::format($booking->amountDue()) }} to pay</span>
                                    </span>
                                    @if ($phone = $call($booking->contact_number ?? $booking->customer?->phone))
                                        <a href="{{ $phone['href'] }}" class="inline-flex min-h-11 items-center gap-1 rounded-control px-2 font-mono text-sm font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac]"><x-ui.icon name="call" :size="16" />{{ $phone['text'] }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endif

                @if ($attention['ready']->isNotEmpty())
                    <x-ui.card class="!p-4 sm:!p-5" data-attention="ready">
                        <h3 class="font-semibold">Ready for pickup, not paid yet</h3>
                        <p class="text-xs text-app-text-2">May be marked Unclaimed {{ $unclaimedAfter }} {{ Str::plural('day', $unclaimedAfter) }} after it became ready.</p>
                        <ul class="mt-3 divide-y divide-app-border">
                            @foreach ($attention['ready'] as $row)
                                <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2.5">
                                    <span class="min-w-0">
                                        <a href="{{ route('owner.orders.show', $row['order']) }}" class="inline-flex min-h-11 items-center font-mono text-sm font-semibold hover:text-brand focus-ring sm:min-h-0">{{ $row['order']->reference_number }}</a>
                                        <span class="text-sm"> · {{ $row['order']->customer?->name }}</span>
                                        <span class="block text-xs text-app-text-2">{{ Money::format($row['order']->amountDue()) }} to pay · waiting {{ $row['days'] }} {{ Str::plural('day', $row['days']) }}@if ($row['unclaimable']) · <span class="font-semibold text-status-amber">can be marked Unclaimed</span>@endif</span>
                                    </span>
                                    @if ($phone = $call($row['order']->customer?->phone))
                                        <a href="{{ $phone['href'] }}" class="inline-flex min-h-11 items-center gap-1 rounded-control px-2 font-mono text-sm font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac]"><x-ui.icon name="call" :size="16" />{{ $phone['text'] }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endif

                @if ($attention['prepay']->isNotEmpty())
                    <x-ui.card class="!p-4 sm:!p-5" data-attention="prepay">
                        <h3 class="font-semibold">To be paid before printing</h3>
                        <p class="text-xs text-app-text-2">Printing starts once the payment is recorded.</p>
                        <ul class="mt-3 divide-y divide-app-border">
                            @foreach ($attention['prepay'] as $order)
                                <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2.5">
                                    <span class="min-w-0">
                                        <a href="{{ route('owner.orders.show', $order) }}" class="inline-flex min-h-11 items-center font-mono text-sm font-semibold hover:text-brand focus-ring sm:min-h-0">{{ $order->reference_number }}</a>
                                        <span class="text-sm"> · {{ $order->customer?->name }}</span>
                                        <span class="block text-xs text-app-text-2">{{ Money::format($order->amountDue()) }} to pay</span>
                                    </span>
                                    <a href="{{ route('owner.payments.index', ['pay' => 'printing_order:'.$order->id]) }}" class="inline-flex min-h-11 items-center gap-1 rounded-control px-2 text-sm font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac]"><x-ui.icon name="add_card" :size="16" />Record payment</a>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endif
            </div>
        @endif
    </section>

    {{-- c) The walk-in customers --}}
    <section class="mt-8" aria-labelledby="customers-title">
        <h2 id="customers-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="group" :size="22" class="text-brand" />Walk-in customers</h2>
        @if ($search !== '')
            <p class="mt-1 text-sm text-app-text-2">Matching “{{ $search }}”. <a href="{{ route('owner.walk-in.index') }}" class="inline-flex min-h-11 items-center font-semibold text-brand hover:underline focus-ring sm:min-h-0">Show all</a></p>
        @endif

        @if ($customers->isEmpty())
            <x-ui.empty-state icon="person_search" title="No walk-in customers yet" description="A walk-in customer is added with their first order or booking." class="mt-4" />
        @else
            <ul class="mt-4 divide-y divide-app-border overflow-hidden rounded-card border border-app-border bg-app-card" data-walk-in-customers>
                @foreach ($customers as $customer)
                    @php($open = (int) $customer->open_orders_count + (int) $customer->open_bookings_count)
                    <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3">
                        <a href="{{ route('owner.customers.show', $customer) }}" class="flex min-w-0 items-center gap-3 rounded focus-ring">
                            <x-ui.user-avatar :user="$customer" size="sm" class="shrink-0" />
                            <span class="min-w-0">
                                <span class="block truncate font-semibold hover:text-brand">{{ $customer->name }}</span>
                                <span class="block text-xs text-app-text-2">Last visit {{ LocalTime::format(WalkInDesk::lastVisit($customer), 'M j, Y') }}@if ($customer->prepay_required) · <span class="font-semibold text-status-amber">pays first</span>@endif</span>
                            </span>
                        </a>
                        <span class="flex flex-wrap items-center gap-3 text-sm">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $open > 0 ? 'bg-status-blue-bg text-status-blue' : 'bg-status-gray-bg text-status-gray' }}">{{ $open }} open</span>
                            @if ($phone = $call($customer->phone))
                                <a href="{{ $phone['href'] }}" class="inline-flex min-h-11 items-center gap-1 rounded-control font-mono font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac]"><x-ui.icon name="call" :size="16" />{{ $phone['text'] }}</a>
                            @else
                                <span class="text-xs text-app-text-2">No phone</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $customers->links() }}</div>
        @endif
    </section>
@endsection
