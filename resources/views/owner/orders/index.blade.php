@extends('layouts.owner')

@section('title', 'Printing orders')

@section('main')
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Printing</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Printing orders</h1>
            <p class="mt-2 text-app-text-2">Every printing request customers have sent. Open one to review it, ask for information, accept it with a price or move it on.</p>
        </div>
    </div>

    @if ($errors->any())
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
            <p>The full list is shown.</p>
        </x-ui.notice>
    @endif

    {{-- Status tabs with counts of every order, as in the design --}}
    <nav aria-label="Filter by status" class="mt-6 -mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <ul class="flex w-max gap-1 rounded-card bg-neutral-100 p-1 dark:bg-white/5">
            <li>
                <a href="{{ route('owner.orders.index', array_filter(['q' => $search, 'from' => $from, 'to' => $to])) }}" @if (! $status) aria-current="page" @endif
                    @class(['inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-semibold transition-colors focus-ring',
                        'bg-ink text-white shadow-sm dark:bg-white dark:text-ink' => ! $status,
                        'text-app-text-2 hover:text-app-text' => $status])>
                    All orders <span class="rounded-full bg-black/10 px-2 py-0.5 text-xs tabular-nums dark:bg-white/15">{{ $counts->sum() }}</span>
                </a>
            </li>
            @foreach ($statuses as $item)
                <li>
                    <a href="{{ route('owner.orders.index', array_filter(['status' => $item->value, 'q' => $search, 'from' => $from, 'to' => $to])) }}" @if ($status === $item) aria-current="page" @endif
                        @class(['inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-semibold transition-colors focus-ring',
                            'bg-ink text-white shadow-sm dark:bg-white dark:text-ink' => $status === $item,
                            'text-app-text-2 hover:text-app-text' => $status !== $item])>
                        {{ $item->label() }}
                        <span @class(['rounded-full px-2 py-0.5 text-xs tabular-nums', 'bg-status-red-bg text-status-red' => in_array($item->value, ['submitted', 'ready_for_pickup'], true) && ($counts[$item->value] ?? 0) > 0, 'bg-black/10 dark:bg-white/15' => ! (in_array($item->value, ['submitted', 'ready_for_pickup'], true) && ($counts[$item->value] ?? 0) > 0)])>{{ $counts[$item->value] ?? 0 }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <form method="GET" action="{{ route('owner.orders.index') }}" role="search"
        class="mt-4 grid gap-4 rounded-card border border-app-border bg-app-card p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end dark:shadow-none">
        @if ($status)
            <input type="hidden" name="status" value="{{ $status->value }}">
        @endif
        <x-ui.input label="Search" name="q" type="search" icon="search" :value="$search" maxlength="60" placeholder="Reference (PR-000001) or customer name" />
        <x-ui.input label="From" name="from" type="date" :value="$from" />
        <x-ui.input label="To" name="to" type="date" :value="$to" />
        <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
            <x-ui.button type="submit">Filter</x-ui.button>
            @if ($hasFilters)
                <x-ui.button :href="route('owner.orders.index')" variant="ghost">Clear</x-ui.button>
            @endif
        </div>
    </form>

    <div class="mt-6">
        @if ($orders->isEmpty())
            <x-ui.card>
                @if ($hasFilters)
                    <x-ui.empty-state icon="search_off" title="No orders found" description="Nothing matches your search or filters. Try different words or dates, or clear the filters.">
                        <x-ui.button :href="route('owner.orders.index')" variant="outline">Clear filters</x-ui.button>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="print" title="No printing orders yet" description="Orders appear here as soon as customers send them." />
                @endif
            </x-ui.card>
        @else
            <div class="hidden overflow-x-auto rounded-card border border-app-border bg-app-card shadow-[0_1px_2px_rgba(0,0,0,0.04)] dark:shadow-none xl:block">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Printing orders</caption>
                    <thead class="bg-neutral-50 text-[11px] uppercase tracking-wider text-app-text-2 dark:bg-white/5">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-bold">Reference</th>
                            <th scope="col" class="px-4 py-3 font-bold">Customer</th>
                            <th scope="col" class="px-4 py-3 font-bold">Service</th>
                            <th scope="col" class="px-4 py-3 font-bold">Sent on</th>
                            <th scope="col" class="px-4 py-3 font-bold">Estimate / confirmed</th>
                            <th scope="col" class="px-4 py-3 font-bold">Status</th>
                            <th scope="col" class="px-4 py-3 font-bold"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-app-border">
                        @foreach ($orders as $order)
                            <tr class="transition-colors hover:bg-black/[0.02] dark:hover:bg-white/[0.03]">
                                <td class="px-4 py-4">
                                    <a href="{{ route('owner.orders.show', $order) }}" class="rounded font-mono text-sm font-bold text-brand hover:underline focus-ring">{{ $order->reference_number }}</a>
                                </td>
                                <td class="max-w-56 px-4 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-status-red-bg text-xs font-bold text-brand" aria-hidden="true">{{ Str::upper(Str::substr($order->customer?->name ?? '?', 0, 1)) }}</span>
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold">{{ $order->customer?->name ?? 'Deleted account' }}</p>
                                            <p class="truncate font-mono text-[11px] text-app-text-2">{{ $order->customer?->phone ?: $order->customer?->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="max-w-64 px-4 py-4">
                                    <p class="truncate font-semibold">{{ $order->serviceName() }}</p>
                                    <p class="text-xs text-app-text-2">{{ $order->quantity }} {{ Str::plural('copy', $order->quantity) }} · {{ $order->files_count }} {{ Str::plural('file', $order->files_count) }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 font-mono text-xs text-app-text-2"><x-ui.date :value="$order->created_at" /></td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    <p class="text-xs text-app-text-2">Est. {{ $order->estimateText() }}</p>
                                    <p class="font-bold tabular-nums {{ $order->confirmed_price !== null ? 'text-brand' : 'text-app-text-2' }}">{{ $order->confirmedText() ?? 'Not confirmed' }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    <x-ui.status-badge :status="$order->status" live />
                                    @if ($order->needsInfo())
                                        <p class="mt-1 text-xs font-semibold text-status-amber">Waiting for the customer</p>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <a href="{{ route('owner.orders.show', $order) }}" class="inline-flex min-h-11 items-center gap-1 rounded-control px-3 text-sm font-semibold text-app-text-2 transition-colors hover:bg-black/5 hover:text-brand focus-ring dark:hover:bg-white/10 sm:min-h-9">
                                        Open<span class="sr-only"> order {{ $order->reference_number }}</span><x-ui.icon name="chevron_right" :size="18" />
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="space-y-3 xl:hidden">
                @foreach ($orders as $order)
                    <li>
                        <a href="{{ route('owner.orders.show', $order) }}" class="block rounded-card border border-app-border bg-app-card p-4 shadow-sm transition-colors hover:border-brand focus-ring dark:shadow-none">
                            <span class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-mono text-sm font-bold text-brand">{{ $order->reference_number }}</span>
                                <x-ui.status-badge :status="$order->status" live />
                            </span>
                            <span class="mt-2 block break-words font-semibold">{{ $order->customer?->name ?? 'Deleted account' }}</span>
                            <span class="block break-words text-sm text-app-text-2">{{ $order->serviceName() }} · {{ $order->quantity }} {{ Str::plural('copy', $order->quantity) }}</span>
                            @if ($order->needsInfo())
                                <span class="mt-1 block text-xs font-semibold text-status-amber">Waiting for the customer</span>
                            @endif
                            <span class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <span>
                                    <span class="block text-xs text-app-text-2">Sent on</span>
                                    <x-ui.date :value="$order->created_at" class="block" />
                                </span>
                                <span>
                                    <span class="block text-xs text-app-text-2">{{ $order->confirmed_price !== null ? 'Confirmed' : 'Estimate' }}</span>
                                    <span class="block font-semibold tabular-nums">{{ $order->confirmedText() ?? $order->estimateText() }}</span>
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">{{ $orders->links('pagination.simple') }}</div>
        @endif
    </div>
@endsection
