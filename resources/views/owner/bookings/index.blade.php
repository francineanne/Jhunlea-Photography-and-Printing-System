@extends('layouts.owner')

@section('title', 'Photography bookings')

@php
    // The tab colour dots of the design: waiting for the studio, waiting for the customer, on the schedule, closed
    $dots = ['requested' => 'bg-status-amber', 'proposed' => 'bg-status-blue', 'confirmed' => 'bg-status-green', 'completed' => 'bg-status-gray', 'rejected' => 'bg-status-red', 'cancelled' => 'bg-status-gray'];
    $keep = array_filter(['q' => $search, 'package' => $package, 'from' => $from, 'to' => $to]);
@endphp

@section('main')
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Photography</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Photography bookings</h1>
            <p class="mt-2 max-w-2xl text-app-text-2">Every photoshoot request. Open one to confirm it, offer another time, reject it, or mark the shoot done. Requests do not hold a time until you confirm them.</p>
        </div>
    </div>

    @if ($errors->any())
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
            <p>The requests waiting for you are shown.</p>
        </x-ui.notice>
    @endif

    {{-- Status tabs with a count on every tab (0 too), as in the design; Requested is the work queue and opens first --}}
    <nav aria-label="Filter by status" class="mt-6 -mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <ul class="flex w-max gap-1 rounded-card bg-neutral-100 p-1 dark:bg-white/5">
            @foreach ($statuses as $item)
                <li>
                    <a href="{{ route('owner.bookings.index', ['status' => $item->value] + $keep) }}" @if ($status === $item) aria-current="page" @endif
                        @class(['inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-semibold transition-colors focus-ring',
                            'bg-app-card text-app-text shadow-sm dark:bg-white/10' => $status === $item,
                            'text-app-text-2 hover:text-app-text' => $status !== $item])>
                        <span class="size-2 rounded-full {{ $dots[$item->value] }}" aria-hidden="true"></span>
                        {{ $item->label() }}
                        <span @class(['rounded-full px-2 py-0.5 text-xs tabular-nums',
                            'bg-status-red-bg text-status-red' => $item->value === 'requested' && ($counts['requested'] ?? 0) > 0,
                            'bg-black/10 dark:bg-white/15' => ! ($item->value === 'requested' && ($counts['requested'] ?? 0) > 0)])>{{ $counts[$item->value] ?? 0 }}</span>
                    </a>
                </li>
            @endforeach
            <li>
                <a href="{{ route('owner.bookings.index', ['status' => 'all'] + $keep) }}" @if (! $status) aria-current="page" @endif
                    @class(['inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-semibold transition-colors focus-ring',
                        'bg-app-card text-app-text shadow-sm dark:bg-white/10' => ! $status,
                        'text-app-text-2 hover:text-app-text' => $status])>
                    All bookings <span class="rounded-full bg-black/10 px-2 py-0.5 text-xs tabular-nums dark:bg-white/15">{{ $counts->sum() }}</span>
                </a>
            </li>
        </ul>
    </nav>

    <form method="GET" action="{{ route('owner.bookings.index') }}" role="search"
        class="mt-4 grid gap-4 rounded-card border border-app-border bg-app-card p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-end">
        <input type="hidden" name="status" value="{{ $status?->value ?? 'all' }}">
        <x-ui.input label="Search" name="q" type="search" icon="search" :value="$search" maxlength="60" placeholder="PB-000001, customer name or contact number" />
        <x-ui.select id="filter-package" name="package" label="Package">
            <option value="">All packages</option>
            @foreach ($packages as $item)
                <option value="{{ $item->id }}" @selected($package === $item->id)>{{ $item->name }}{{ $item->deleted_at ? ' (removed)' : '' }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input label="Session from" name="from" type="date" :value="$from" />
        <x-ui.input label="Session to" name="to" type="date" :value="$to" />
        <div class="flex gap-2 sm:col-span-2 xl:col-span-1">
            <x-ui.button type="submit">Filter</x-ui.button>
            @if ($hasFilters)
                <x-ui.button :href="route('owner.bookings.index', ['status' => $status?->value ?? 'all'])" variant="ghost">Clear</x-ui.button>
            @endif
        </div>
    </form>

    <div class="mt-6">
        @if ($bookings->isEmpty())
            <x-ui.card>
                @if ($hasFilters)
                    <x-ui.empty-state icon="search_off" title="No bookings found" description="Nothing matches your search or filters. Try other words or dates, or clear the filters.">
                        <x-ui.button :href="route('owner.bookings.index', ['status' => $status?->value ?? 'all'])" variant="outline">Clear filters</x-ui.button>
                    </x-ui.empty-state>
                @elseif ($status)
                    <x-ui.empty-state icon="event_available" title="No {{ strtolower($status->label()) }} bookings" description="{{ $status->value === 'requested' ? 'Nothing is waiting for you. New requests appear here as soon as customers send them.' : 'Bookings with this status appear here.' }}" />
                @else
                    <x-ui.empty-state icon="photo_camera" title="No bookings yet" description="Bookings appear here as soon as customers send a request." />
                @endif
            </x-ui.card>
        @else
            <div class="hidden overflow-x-auto rounded-card border border-app-border bg-app-card shadow-[0_1px_2px_rgba(0,0,0,0.04)] dark:shadow-none min-[1400px]:block">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Photography bookings</caption>
                    <thead class="bg-neutral-50 text-[11px] uppercase tracking-wider text-app-text-2 dark:bg-white/5">
                        <tr>
                            <th scope="col" class="px-3 py-3 font-bold">Reference</th>
                            <th scope="col" class="px-3 py-3 font-bold">Customer</th>
                            <th scope="col" class="px-3 py-3 font-bold">Package</th>
                            <th scope="col" class="px-3 py-3 font-bold">Requested / confirmed time</th>
                            <th scope="col" class="px-3 py-3 font-bold">Price</th>
                            <th scope="col" class="px-3 py-3 font-bold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-app-border">
                        @foreach ($bookings as $booking)
                            @php $agreed = in_array($booking->status->value, ['confirmed', 'completed'], true); @endphp
                            <tr class="transition-colors hover:bg-black/[0.02] dark:hover:bg-white/[0.03]">
                                <td class="whitespace-nowrap px-3 py-4 align-top">
                                    <a href="{{ route('owner.bookings.show', $booking) }}" class="inline-flex min-h-9 items-center gap-0.5 rounded font-mono text-sm font-bold text-brand hover:underline focus-ring">{{ $booking->reference_number }}<x-ui.icon name="chevron_right" :size="16" /></a>
                                    <p class="mt-0.5 text-[11px] uppercase text-app-text-2"><x-ui.date :value="$booking->created_at" format="M j" /></p>
                                </td>
                                <td class="px-3 py-4 align-top">
                                    <div class="flex w-52 items-center gap-3">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-status-red-bg text-xs font-bold text-brand" aria-hidden="true">{{ Str::upper(Str::substr($booking->customer?->name ?? '?', 0, 1)) }}</span>
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold">{{ $booking->customer?->name ?? 'Deleted account' }}</p>
                                            <p class="truncate font-mono text-[11px] text-app-text-2">{{ $booking->contact_number }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-4 align-top">
                                    <p class="w-40 truncate font-semibold">{{ $booking->snapshot['package']['name'] ?? $booking->package?->name }}</p>
                                    <p class="text-xs text-app-text-2">{{ $booking->durationMinutes() }} mins · {{ $booking->group_size }} {{ $booking->group_size === 1 ? 'person' : 'people' }}</p>
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 align-top">
                                    {{-- Requested and confirmed are always shown apart --}}
                                    <p><span class="text-xs text-app-text-2">Asked:</span> <x-ui.date :value="$booking->requested_starts_at" format="M j, g:i A" /></p>
                                    @if ($agreed)
                                        <p class="mt-0.5 flex items-center gap-1.5"><span class="text-xs text-app-text-2">Confirmed:</span> <x-ui.date :value="$booking->starts_at" format="M j, g:i A" class="font-semibold" />
                                            @if ($booking->starts_at->eq($booking->requested_starts_at))
                                                <span class="rounded bg-status-green-bg px-1 text-[10px] font-bold text-status-green">Matches</span>
                                            @else
                                                <span class="rounded bg-status-amber-bg px-1 text-[10px] font-bold text-status-amber">Moved</span>
                                            @endif
                                        </p>
                                    @elseif ($booking->status->value === 'proposed' && $booking->proposed_starts_at)
                                        <p class="mt-0.5"><span class="text-xs text-app-text-2">Offered:</span> <x-ui.date :value="$booking->proposed_starts_at" format="M j, g:i A" class="font-semibold text-status-blue" /></p>
                                    @else
                                        <p class="mt-0.5 text-xs italic text-app-text-2">Not confirmed</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 align-top">
                                    <p class="text-xs text-app-text-2">Est. {{ $booking->estimateText() }}</p>
                                    <p class="font-bold tabular-nums {{ $booking->confirmed_price !== null ? 'text-brand' : 'text-app-text-2' }}">{{ $booking->confirmedText() ?? 'Not confirmed' }}</p>
                                </td>
                                <td class="px-3 py-4 align-top"><x-ui.status-badge :status="$booking->status" live /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Six columns need about 1100px next to the sidebar, so cards are used up to 1400px wide --}}
            <ul class="space-y-3 min-[1400px]:hidden">
                @foreach ($bookings as $booking)
                    @php $agreed = in_array($booking->status->value, ['confirmed', 'completed'], true); @endphp
                    <li>
                        <a href="{{ route('owner.bookings.show', $booking) }}" class="block rounded-card border border-app-border bg-app-card p-4 shadow-sm transition-colors hover:border-brand focus-ring dark:shadow-none">
                            <span class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-mono text-sm font-bold text-brand">{{ $booking->reference_number }}</span>
                                <x-ui.status-badge :status="$booking->status" live />
                            </span>
                            <span class="mt-2 block break-words font-semibold">{{ $booking->customer?->name ?? 'Deleted account' }}</span>
                            <span class="block break-words text-sm text-app-text-2">{{ $booking->snapshot['package']['name'] ?? $booking->package?->name }} · {{ $booking->group_size }} {{ $booking->group_size === 1 ? 'person' : 'people' }}</span>
                            <span class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <span class="min-w-0">
                                    <span class="block text-xs text-app-text-2">{{ $agreed ? 'Confirmed time' : 'Requested time' }}</span>
                                    <x-ui.date :value="$booking->starts_at" class="block" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-xs text-app-text-2">{{ $booking->confirmed_price !== null ? 'Confirmed' : 'Estimate' }}</span>
                                    <span class="block break-words font-semibold tabular-nums">{{ $booking->confirmedText() ?? $booking->estimateText() }}</span>
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">{{ $bookings->links('pagination.simple') }}</div>
        @endif
    </div>
@endsection
