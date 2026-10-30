@extends('layouts.owner')

@section('title', 'Customers')

{{--
    The customer directory (Phase 6A), after design/admin/customers_client_directory_profiles: tiles, search and the
    "awaiting payment" filter, then one row per customer (a card on phones). Read only: no intake, SMS or email
    buttons, no notes, no tiers or loyalty labels (the system has none of these).
--}}
@php
    use App\Support\Money;

    $peso = fn (int $cents) => Money::format(Money::fromCents($cents));
@endphp

@section('main')
    <div>
        <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Customers</p>
        <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Customer directory</h1>
        <p class="mt-2 max-w-2xl text-app-text-2">Everyone with a customer account, newest first. Open a customer to see their orders, photoshoots and payments.</p>
    </div>

    @if ($errors->any())
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
            <p>The full list is shown.</p>
        </x-ui.notice>
    @endif

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-tile label="Customers" glow>
            <x-slot:aside><x-ui.icon name="group" :size="20" class="text-brand" /></x-slot:aside>
            <p class="mt-4 text-2xl font-bold tabular-nums sm:text-3xl">{{ $tiles['total'] }}</p>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="New this month" :delay="60">
            <x-slot:aside><x-ui.icon name="person_add" :size="20" class="text-app-text-2" /></x-slot:aside>
            <p class="mt-4 text-2xl font-bold tabular-nums">{{ $tiles['newThisMonth'] }}</p>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="With an open request" :delay="120">
            <x-slot:aside><x-ui.icon name="pending" :size="20" class="text-app-text-2" /></x-slot:aside>
            <p class="mt-4 text-2xl font-bold tabular-nums">{{ $tiles['open'] }}</p>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="With something awaiting payment" :delay="180">
            <x-slot:aside><x-ui.icon name="pending_actions" :size="20" class="text-status-red" /></x-slot:aside>
            <p @class(['mt-4 text-2xl font-bold tabular-nums', 'text-status-red' => $tiles['awaiting'] > 0])>{{ $tiles['awaiting'] }}</p>
        </x-ui.stat-tile>
    </div>

    <form method="GET" action="{{ route('owner.customers.index') }}" role="search"
        class="mt-6 grid gap-4 rounded-card border border-app-border bg-app-card p-4 sm:grid-cols-[1fr_auto] sm:items-end dark:shadow-none">
        <x-ui.input label="Search" name="q" type="search" icon="search" :value="$filters['search']" maxlength="60" placeholder="Name, email or phone" />
        <x-ui.checkbox name="awaiting" value="1" :checked="$filters['awaiting']">Only with something awaiting payment</x-ui.checkbox>
        <div class="flex gap-2 sm:col-span-2">
            <x-ui.button type="submit">Filter</x-ui.button>
            @if ($hasFilters)
                <x-ui.button :href="route('owner.customers.index')" variant="ghost">Clear</x-ui.button>
            @endif
        </div>
    </form>

    <section class="mt-6" aria-labelledby="customers-title">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="customers-title" class="font-display text-xl font-semibold">Customer accounts</h2>
            <span class="font-mono text-xs text-app-text-2">{{ $customers->total() }} {{ Str::plural('customer', $customers->total()) }} · newest first</span>
        </div>

        @if ($customers->isEmpty() && $customers->total() > 0)
            <p class="rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">This page is past the end of the list. <a href="{{ $customers->url(1) }}" class="rounded font-semibold text-brand hover:underline focus-ring">Go to the first page</a></p>
        @elseif ($customers->isEmpty())
            <x-ui.card>
                @if ($hasFilters)
                    <x-ui.empty-state icon="search_off" title="No customers found" description="Nothing matches your search or filter. Try other words, or clear the filters.">
                        <x-ui.button :href="route('owner.customers.index')" variant="outline">Clear filters</x-ui.button>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="group" title="No customers yet" description="Customers appear here once they create an account on the site." />
                @endif
            </x-ui.card>
        @else
            <div class="hidden overflow-x-auto rounded-card border border-app-border bg-app-card shadow-[0_1px_2px_rgba(0,0,0,0.04)] dark:shadow-none xl:block">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Customers, newest first</caption>
                    <thead class="bg-neutral-50 text-[11px] uppercase tracking-wider text-app-text-2 dark:bg-white/5">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-bold">Customer</th>
                            <th scope="col" class="px-4 py-3 font-bold">Phone</th>
                            <th scope="col" class="px-4 py-3 font-bold">Joined</th>
                            <th scope="col" class="px-4 py-3 font-bold">Requests</th>
                            <th scope="col" class="px-4 py-3 font-bold">Last request</th>
                            <th scope="col" class="px-4 py-3 text-right font-bold">Awaiting payment</th>
                            <th scope="col" class="px-4 py-3 font-bold"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-app-border">
                        @foreach ($customers as $customer)
                            <tr class="transition-colors hover:bg-black/[0.02] dark:hover:bg-white/[0.03]">
                                <td class="max-w-60 px-4 py-4">
                                    <p class="truncate font-semibold" title="{{ $customer->name }}">{{ $customer->name }}</p>
                                    <p class="truncate text-xs text-app-text-2" title="{{ $customer->email }}">{{ $customer->email }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 font-mono text-xs">{{ $customer->phone ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-4"><x-ui.date :value="$customer->created_at" format="M j, Y" /></td>
                                <td class="whitespace-nowrap px-4 py-4 text-xs tabular-nums">
                                    <span class="block">{{ $customer->printing_orders_count }} {{ Str::plural('order', $customer->printing_orders_count) }}</span>
                                    <span class="block text-app-text-2">{{ $customer->photography_bookings_count }} {{ Str::plural('booking', $customer->photography_bookings_count) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-xs"><x-ui.date :value="$customer->last_activity_at" format="M j, Y" /></td>
                                <td @class(['whitespace-nowrap px-4 py-4 text-right font-bold tabular-nums', 'text-status-red' => $customer->awaiting_cents > 0, 'text-app-text-2' => $customer->awaiting_cents === 0])>{{ $peso($customer->awaiting_cents) }}</td>
                                <td class="px-4 py-4 text-right"><x-ui.button :href="route('owner.customers.show', $customer)" variant="outline" size="sm">View</x-ui.button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Phones and tablets: one card per customer --}}
            <ul class="space-y-3 xl:hidden">
                @foreach ($customers as $customer)
                    <li>
                        <a href="{{ route('owner.customers.show', $customer) }}" class="block rounded-card border border-app-border bg-app-card p-4 shadow-sm transition-colors hover:border-brand focus-ring dark:shadow-none">
                            <span class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block break-words font-semibold">{{ $customer->name }}</span>
                                    <span class="block break-all text-xs text-app-text-2">{{ $customer->email }}</span>
                                </span>
                                @if ($customer->awaiting_cents > 0)
                                    <x-ui.badge tone="red" class="shrink-0">Due {{ $peso($customer->awaiting_cents) }}</x-ui.badge>
                                @endif
                            </span>
                            <span class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                                <span class="min-w-0"><span class="block text-[10px] font-bold uppercase tracking-wider text-app-text-2">Phone</span><span class="break-all font-mono text-xs">{{ $customer->phone ?: '—' }}</span></span>
                                <span class="min-w-0"><span class="block text-[10px] font-bold uppercase tracking-wider text-app-text-2">Joined</span><x-ui.date :value="$customer->created_at" format="M j, Y" class="text-xs" /></span>
                                <span class="min-w-0"><span class="block text-[10px] font-bold uppercase tracking-wider text-app-text-2">Requests</span><span class="text-xs tabular-nums">{{ $customer->printing_orders_count }} {{ Str::plural('order', $customer->printing_orders_count) }} · {{ $customer->photography_bookings_count }} {{ Str::plural('booking', $customer->photography_bookings_count) }}</span></span>
                                <span class="min-w-0"><span class="block text-[10px] font-bold uppercase tracking-wider text-app-text-2">Last request</span><x-ui.date :value="$customer->last_activity_at" format="M j, Y" class="text-xs" /></span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <p class="mt-4 font-mono text-xs text-app-text-2">Showing {{ $customers->firstItem() }} to {{ $customers->lastItem() }} of {{ $customers->total() }}</p>
            <div class="mt-4">{{ $customers->links('pagination.simple') }}</div>
        @endif
    </section>
@endsection
