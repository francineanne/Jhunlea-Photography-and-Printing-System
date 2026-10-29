@extends('layouts.customer')

@section('title', 'My requests')

{{--
    All of the customer's printing orders and photography bookings in one list (Phase 5C), after
    design/customer/request_history_printing_photography: kind tabs with counts, status, search and dates, one row per
    request with its status and payment, and pagination that keeps the filters. On phones each row is a card.
--}}
@php
    use App\Models\PrintingOrder;

    $link = fn (array $change) => route('customer.requests.index', array_filter(array_merge(['kind' => $filters['kind'], 'q' => $filters['search'] ?: null, 'from' => $filters['from'], 'to' => $filters['to']], $change)));
    $tabs = [null => ['All requests', $counts['all']], 'printing' => ['Printing', $counts['printing']], 'photography' => ['Photography', $counts['photography']]];
    $title = fn ($request) => $request instanceof PrintingOrder ? $request->serviceName() : ($request->snapshot['package']['name'] ?? $request->package?->name ?? 'Photoshoot');
    $url = fn ($request) => $request instanceof PrintingOrder ? route('customer.printing.show', $request) : route('customer.bookings.show', $request);
@endphp

@section('main')
    <div>
        <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>My account / Requests</p>
        <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">My requests</h1>
        <p class="mt-2 max-w-2xl text-app-text-2">Every printing order and photoshoot you sent, newest first. Open one for its details, files and payment.</p>
    </div>

    @if ($errors->any())
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
            <p>All your requests are shown.</p>
        </x-ui.notice>
    @endif

    <nav aria-label="Filter by kind" class="mt-6 -mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <ul class="flex w-max gap-1 rounded-card bg-neutral-100 p-1 dark:bg-white/5">
            @foreach ($tabs as $kind => [$label, $count])
                @php($current = ($filters['kind'] ?? '') === (string) $kind)
                <li>
                    <a href="{{ $link(['kind' => $kind ?: null]) }}" @if ($current) aria-current="page" @endif
                        @class(['inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-semibold transition-colors focus-ring',
                            'bg-ink text-white shadow-sm dark:bg-white dark:text-ink' => $current,
                            'text-app-text-2 hover:text-app-text' => ! $current])>
                        {{ $label }} <span class="rounded-full bg-black/10 px-2 py-0.5 text-xs tabular-nums dark:bg-white/15">{{ $count }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <form method="GET" action="{{ route('customer.requests.index') }}" role="search"
        class="mt-4 grid gap-4 rounded-card border border-app-border bg-app-card p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end dark:shadow-none">
        @if ($filters['kind'])
            <input type="hidden" name="kind" value="{{ $filters['kind'] }}">
        @endif
        <x-ui.input label="Search" name="q" type="search" icon="search" :value="$filters['search']" maxlength="60" placeholder="Reference (PR-, PB-) or name" />
        @if ($statuses !== [])
            <x-ui.select label="Status" name="status">
                <option value="">Any status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.select>
        @else
            <p class="self-end pb-2.5 text-xs text-app-text-2">Choose Printing or Photography to filter by status.</p>
        @endif
        <x-ui.input label="From" name="from" type="date" :value="$filters['from']" />
        <x-ui.input label="To" name="to" type="date" :value="$filters['to']" />
        <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
            <x-ui.button type="submit">Filter</x-ui.button>
            @if ($hasFilters)
                <x-ui.button :href="route('customer.requests.index')" variant="ghost">Clear</x-ui.button>
            @endif
        </div>
    </form>

    <div class="mt-6">
        @if ($requests->isEmpty())
            <x-ui.card>
                @if ($hasFilters)
                    <x-ui.empty-state icon="search_off" title="No requests found" description="Nothing matches your search or filters. Try other words or dates, or clear the filters.">
                        <x-ui.button :href="route('customer.requests.index')" variant="outline">Clear filters</x-ui.button>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="history" title="No requests yet" description="Printing orders and photoshoots you send appear here.">
                        <div class="flex flex-wrap justify-center gap-2">
                            <x-ui.button :href="route('customer.printing.create')">Request printing</x-ui.button>
                            <x-ui.button :href="route('customer.bookings.create')" variant="outline">Book a photoshoot</x-ui.button>
                        </div>
                    </x-ui.empty-state>
                @endif
            </x-ui.card>
        @else
            <div class="hidden overflow-x-auto rounded-card border border-app-border bg-app-card shadow-[0_1px_2px_rgba(0,0,0,0.04)] dark:shadow-none lg:block">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">My printing orders and photoshoots, newest first</caption>
                    <thead class="bg-neutral-50 text-[11px] uppercase tracking-wider text-app-text-2 dark:bg-white/5">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-bold">Request</th>
                            <th scope="col" class="px-4 py-3 font-bold">Sent on</th>
                            <th scope="col" class="px-4 py-3 font-bold">Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-bold">Price</th>
                            <th scope="col" class="px-4 py-3 font-bold"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-app-border">
                        @foreach ($requests as $request)
                            @php($isOrder = $request instanceof PrintingOrder)
                            <tr class="group transition-colors hover:bg-black/[0.02] dark:hover:bg-white/[0.03]">
                                <td class="max-w-md px-4 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-neutral-100 text-app-text-2 transition-transform group-hover:scale-95 dark:bg-white/10" aria-hidden="true"><x-ui.icon :name="$isOrder ? 'print' : 'photo_camera'" :size="22" /></span>
                                        <div class="min-w-0">
                                            <p class="flex flex-wrap items-center gap-2"><span class="rounded bg-neutral-100 px-2 py-0.5 font-mono text-xs font-bold dark:bg-white/10">{{ $request->reference_number }}</span><span class="text-[11px] font-semibold uppercase tracking-wider text-app-text-2">{{ $isOrder ? 'Printing' : 'Photography' }}</span></p>
                                            <p class="mt-1 truncate font-semibold" title="{{ $title($request) }}">{{ $title($request) }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4"><x-ui.date :value="$request->created_at" format="M j, Y" /><span class="block font-mono text-[11px] text-app-text-2"><x-ui.date :value="$request->created_at" format="g:i A" /></span></td>
                                <td class="px-4 py-4"><span class="flex flex-col items-start gap-1"><x-ui.status-badge :status="$request->status" live /><x-payment.badge :payable="$request" /></span></td>
                                <td class="whitespace-nowrap px-4 py-4 text-right">
                                    <p class="font-bold tabular-nums">{{ $request->confirmedText() ?? $request->estimateText() }}</p>
                                    <p class="text-[11px] uppercase tracking-wider text-app-text-2">{{ $request->confirmed_price !== null ? 'Confirmed price' : 'Estimate' }}</p>
                                </td>
                                <td class="px-4 py-4 text-right"><x-ui.button :href="$url($request)" variant="outline" size="sm">View details</x-ui.button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="space-y-3 lg:hidden">
                @foreach ($requests as $request)
                    @php($isOrder = $request instanceof PrintingOrder)
                    <li>
                        <a href="{{ $url($request) }}" class="group block rounded-card border border-app-border bg-app-card p-4 shadow-sm transition-colors hover:border-brand focus-ring dark:shadow-none">
                            <span class="flex flex-wrap items-center justify-between gap-2">
                                <span class="flex items-center gap-2"><span class="rounded bg-neutral-100 px-2 py-0.5 font-mono text-xs font-bold dark:bg-white/10">{{ $request->reference_number }}</span><span class="text-[11px] font-semibold uppercase tracking-wider text-app-text-2">{{ $isOrder ? 'Printing' : 'Photography' }}</span></span>
                                <span class="flex flex-wrap gap-1"><x-ui.status-badge :status="$request->status" live /><x-payment.badge :payable="$request" /></span>
                            </span>
                            <span class="mt-2 block break-words font-semibold">{{ Str::limit($title($request), 120) }}</span>
                            <span class="mt-2 flex items-end justify-between gap-3 text-sm">
                                <x-ui.date :value="$request->created_at" format="M j, Y" class="text-app-text-2" />
                                <span class="text-right"><span class="block text-[11px] uppercase tracking-wider text-app-text-2">{{ $request->confirmed_price !== null ? 'Confirmed price' : 'Estimate' }}</span><span class="font-bold tabular-nums">{{ $request->confirmedText() ?? $request->estimateText() }}</span></span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <p class="mt-4 font-mono text-xs text-app-text-2">Showing {{ $requests->firstItem() }} to {{ $requests->lastItem() }} of {{ $requests->total() }}</p>
            <div class="mt-4">{{ $requests->links('pagination.simple') }}</div>
        @endif
    </div>
@endsection
