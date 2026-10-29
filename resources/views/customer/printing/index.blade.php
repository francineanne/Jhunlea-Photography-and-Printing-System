@extends('layouts.customer')

@section('title', 'My printing orders | '.config('app.name'))

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('customer.dashboard') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">My dashboard</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">My printing orders</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <header class="max-w-2xl">
            <h1 class="font-display text-3xl font-semibold leading-tight sm:text-4xl">My printing orders</h1>
            <p class="mt-2 text-app-text-2">Follow your printing requests, see the estimate or the confirmed price, and open an order to see its files and history.</p>
        </header>
        <x-ui.button :href="route('customer.printing.create')"><x-ui.icon name="add_photo_alternate" :size="20" />New printing request</x-ui.button>
    </div>

    <x-ui.notice variant="payment" class="mt-6">
        <p class="text-app-text-2">Nothing is charged online. The shop confirms the final price before you pay.</p>
    </x-ui.notice>

    @if ($errors->has('status'))
        <x-ui.notice class="mt-4"><p>{{ $errors->first('status') }}</p></x-ui.notice>
    @endif

    {{-- Status tabs with counts, as in the design. Only statuses you have orders in are listed --}}
    <nav aria-label="Filter by status" class="mt-6 -mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <ul class="flex w-max gap-1 sm:w-auto sm:flex-wrap">
            <li>
                <a href="{{ route('customer.printing.index') }}" @if (! $status) aria-current="page" @endif
                    @class(['relative inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-semibold transition-colors focus-ring',
                        'bg-app-card text-brand shadow-sm' => ! $status,
                        'text-app-text-2 hover:bg-black/5 hover:text-app-text dark:hover:bg-white/10' => $status])>
                    All orders <span class="rounded-full bg-neutral-200 px-2 py-0.5 text-xs tabular-nums dark:bg-white/10">{{ $counts->sum() }}</span>
                    @if (! $status)<span class="absolute inset-x-3 bottom-0 h-0.5 rounded-full bg-brand" aria-hidden="true"></span>@endif
                </a>
            </li>
            @foreach ($statuses as $item)
                @if (($counts[$item->value] ?? 0) > 0 || $status === $item)
                    <li>
                        <a href="{{ route('customer.printing.index', ['status' => $item->value]) }}" @if ($status === $item) aria-current="page" @endif
                            @class(['relative inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-semibold transition-colors focus-ring',
                                'bg-app-card text-brand shadow-sm' => $status === $item,
                                'text-app-text-2 hover:bg-black/5 hover:text-app-text dark:hover:bg-white/10' => $status !== $item])>
                            {{ $item->label() }} <span class="rounded-full bg-neutral-200 px-2 py-0.5 text-xs tabular-nums dark:bg-white/10">{{ $counts[$item->value] ?? 0 }}</span>
                            @if ($status === $item)<span class="absolute inset-x-3 bottom-0 h-0.5 rounded-full bg-brand" aria-hidden="true"></span>@endif
                        </a>
                    </li>
                @endif
            @endforeach
        </ul>
    </nav>

    <div class="mt-4">
        @if ($orders->isEmpty())
            <x-ui.card>
                @if ($status)
                    <x-ui.empty-state icon="filter_alt_off" title="No orders with this status" description="You have no printing orders that are {{ strtolower($status->label()) }} right now.">
                        <x-ui.button :href="route('customer.printing.index')" variant="outline">Show all orders</x-ui.button>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="print" title="You have no printing orders yet" description="Send your first printing request. You can follow it here once it is sent.">
                        <x-ui.button :href="route('customer.printing.create')">Request printing</x-ui.button>
                    </x-ui.empty-state>
                @endif
            </x-ui.card>
        @else
            {{-- Table on large screens --}}
            <div class="hidden overflow-x-auto rounded-card border border-app-border bg-app-card shadow-[0_1px_2px_rgba(0,0,0,0.04)] dark:shadow-none lg:block">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">My printing orders</caption>
                    <thead class="bg-neutral-50 text-[11px] uppercase tracking-wider text-app-text-2 dark:bg-white/5">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-bold">Reference</th>
                            <th scope="col" class="px-4 py-3 font-bold">Service</th>
                            <th scope="col" class="px-4 py-3 font-bold">Sent on</th>
                            <th scope="col" class="px-4 py-3 font-bold">Copies</th>
                            <th scope="col" class="px-4 py-3 font-bold">Status</th>
                            <th scope="col" class="px-4 py-3 font-bold">Price</th>
                            <th scope="col" class="px-4 py-3 font-bold"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-app-border">
                        @foreach ($orders as $order)
                            <tr @class(['transition-colors hover:bg-black/[0.02] dark:hover:bg-white/[0.03]', 'bg-status-red-bg/40' => $order->needsInfo()])>
                                <td class="px-4 py-4">
                                    <span class="inline-flex items-center gap-1.5 rounded bg-neutral-100 px-2 py-1 font-mono text-xs font-semibold dark:bg-white/10">{{ $order->reference_number }}</span>
                                    @if ($order->needsInfo())
                                        <span class="mt-1 flex items-center gap-1 text-xs font-semibold text-status-red"><x-ui.icon name="warning" :size="14" />Shop needs info</span>
                                    @endif
                                </td>
                                <td class="max-w-64 px-4 py-4">
                                    <p class="truncate font-semibold">{{ $order->serviceName() }}</p>
                                    <p class="text-xs text-app-text-2">{{ $order->files_count }} {{ Str::plural('file', $order->files_count) }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 font-mono text-xs text-app-text-2"><x-ui.date :value="$order->created_at" /></td>
                                <td class="px-4 py-4 tabular-nums">{{ $order->quantity }}</td>
                                <td class="px-4 py-4"><span class="flex flex-col items-start gap-1"><x-ui.status-badge :status="$order->status" live /><x-payment.badge :payable="$order" /></span></td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    <p class="font-semibold tabular-nums">{{ $order->confirmedText() ?? $order->estimateText() }}</p>
                                    <p class="font-mono text-[11px] uppercase text-app-text-2">{{ $order->confirmed_price !== null ? 'Confirmed' : ($order->estimated_price !== null ? 'Estimate' : '') }}</p>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <a href="{{ route('customer.printing.show', $order) }}" class="inline-flex min-h-11 items-center gap-1.5 rounded-control border border-app-border px-3 text-sm font-semibold transition-colors hover:border-brand hover:text-brand focus-ring sm:min-h-9">
                                        <x-ui.icon name="visibility" :size="18" />View<span class="sr-only"> order {{ $order->reference_number }}</span>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Cards on phones and tablets --}}
            <ul class="space-y-3 lg:hidden">
                @foreach ($orders as $order)
                    <li>
                        <a href="{{ route('customer.printing.show', $order) }}" @class(['block rounded-card border bg-app-card p-4 shadow-sm transition-colors hover:border-brand focus-ring dark:shadow-none', 'border-status-red/40' => $order->needsInfo(), 'border-app-border' => ! $order->needsInfo()])>
                            <span class="flex flex-wrap items-center justify-between gap-2">
                                <span class="rounded bg-neutral-100 px-2 py-1 font-mono text-xs font-semibold dark:bg-white/10">{{ $order->reference_number }}</span>
                                <span class="flex flex-wrap gap-1"><x-ui.status-badge :status="$order->status" live /><x-payment.badge :payable="$order" /></span>
                            </span>
                            <span class="mt-3 block break-words font-semibold">{{ $order->serviceName() }}</span>
                            @if ($order->needsInfo())
                                <span class="mt-1 flex items-center gap-1 text-xs font-semibold text-status-red"><x-ui.icon name="warning" :size="14" />The shop needs more information</span>
                            @endif
                            <span class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <span>
                                    <span class="block text-xs text-app-text-2">Sent on</span>
                                    <x-ui.date :value="$order->created_at" class="block" />
                                </span>
                                <span>
                                    <span class="block text-xs text-app-text-2">{{ $order->confirmed_price !== null ? 'Confirmed price' : 'Estimate' }}</span>
                                    <span class="block font-semibold tabular-nums">{{ $order->confirmedText() ?? $order->estimateText() }}</span>
                                </span>
                            </span>
                            <span class="mt-3 flex items-center justify-between text-xs text-app-text-2">
                                <span>{{ $order->quantity }} {{ Str::plural('copy', $order->quantity) }} · {{ $order->files_count }} {{ Str::plural('file', $order->files_count) }}</span>
                                <span class="inline-flex items-center gap-1 font-semibold text-brand">View order<x-ui.icon name="arrow_forward" :size="16" /></span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">{{ $orders->links('pagination.simple') }}</div>
        @endif
    </div>
@endsection
