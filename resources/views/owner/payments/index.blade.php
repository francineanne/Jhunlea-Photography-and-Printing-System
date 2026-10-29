@extends('layouts.owner')

@section('title', 'Payments')

@php
    use App\Enums\PaymentMethod;
    use App\Models\PrintingOrder;
    use Illuminate\Support\Carbon;
    use App\Support\Money;

    $peso = fn (int $cents) => Money::format(Money::fromCents($cents));
    $period = match (true) {
        $filters['from'] && $filters['to'] => Carbon::parse($filters['from'])->format('M j, Y').' to '.Carbon::parse($filters['to'])->format('M j, Y'),
        (bool) $filters['from'] => 'From '.Carbon::parse($filters['from'])->format('M j, Y'),
        (bool) $filters['to'] => 'Up to '.Carbon::parse($filters['to'])->format('M j, Y'),
        default => 'All dates',
    };
    $cash = $totals['methods'][PaymentMethod::Cash->value];
    $gcash = $totals['methods'][PaymentMethod::Gcash->value];
    $cashShare = $totals['cents'] > 0 ? round($cash['cents'] / $totals['cents'] * 100, 1) : 0;
    $gcashShare = $totals['cents'] > 0 ? round(100 - $cashShare, 1) : 0;
    $payableUrl = fn ($payable) => $payable instanceof PrintingOrder ? route('owner.orders.show', $payable) : route('owner.bookings.show', $payable);
@endphp

@section('main')
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Payments</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Payments ledger</h1>
            <p class="mt-2 max-w-2xl text-app-text-2">Every payment recorded at the counter, and the orders and photoshoots still waiting for theirs. To record a payment, open the order or booking.</p>
        </div>
    </div>

    <x-ui.notice variant="payment" class="mt-6">
        <p class="text-app-text-2">The system only records and monitors payments received face to face, in cash or by GCash. It never takes a payment online. Each order or photoshoot is paid in full, once.</p>
    </x-ui.notice>

    @if ($errors->any())
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
            <p>The full ledger is shown.</p>
        </x-ui.notice>
    @endif

    {{-- Recorded payments only (voided ones never count): never called revenue or sales --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-tile label="Recorded payments" glow>
            <x-slot:aside><x-ui.icon name="account_balance_wallet" :size="20" class="text-brand" /></x-slot:aside>
            <div class="mt-4">
                <p class="text-2xl font-bold tabular-nums sm:text-3xl">{{ $peso($totals['cents']) }}</p>
                <p class="mt-1 text-xs text-app-text-2">{{ $totals['count'] }} {{ Str::plural('payment', $totals['count']) }} · {{ $period }}</p>
            </div>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Cash" :delay="60">
            <x-slot:aside><x-ui.icon name="payments" :size="20" class="text-app-text-2" /></x-slot:aside>
            <div class="mt-4">
                <p class="text-2xl font-bold tabular-nums">{{ $peso($cash['cents']) }}</p>
                <p class="mt-1 text-xs text-app-text-2">{{ $cash['count'] }} {{ Str::plural('payment', $cash['count']) }}</p>
            </div>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="GCash" :delay="120">
            <x-slot:aside><x-ui.icon name="qr_code_2" :size="20" class="text-app-text-2" /></x-slot:aside>
            <div class="mt-4">
                <p class="text-2xl font-bold tabular-nums">{{ $peso($gcash['cents']) }}</p>
                <p class="mt-1 text-xs text-app-text-2">{{ $gcash['count'] }} {{ Str::plural('payment', $gcash['count']) }}</p>
            </div>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Awaiting payment" :delay="180">
            <x-slot:aside><x-ui.icon name="pending_actions" :size="20" class="text-status-red" /></x-slot:aside>
            <div class="mt-4">
                <p class="text-2xl font-bold tabular-nums {{ $awaiting['count'] > 0 ? 'text-status-red' : '' }}">{{ $peso($awaiting['cents']) }}</p>
                <p class="mt-1 text-xs text-app-text-2">{{ $awaiting['count'] }} confirmed {{ Str::plural('price', $awaiting['count']) }} not paid yet · any date</p>
            </div>
        </x-ui.stat-tile>
    </div>

    <form method="GET" action="{{ route('owner.payments.index') }}" role="search"
        class="mt-6 grid gap-4 rounded-card border border-app-border bg-app-card p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end dark:shadow-none">
        <div class="sm:col-span-2 lg:col-span-2">
            <x-ui.input label="Search" name="q" type="search" icon="search" :value="$filters['search']" maxlength="60" placeholder="PAY, PR, PB, customer or GCash ref." />
        </div>
        <x-ui.select label="Method" name="method">
            <option value="">All methods</option>
            @foreach ($methods as $method)
                <option value="{{ $method->value }}" @selected($filters['method'] === $method)>{{ $method->label() }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="Status" name="status">
            <option value="">Any status</option>
            @foreach ($statuses as $item)
                <option value="{{ $item->value }}" @selected($filters['status'] === $item)>{{ $item->label() }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input label="From" name="from" type="date" :value="$filters['from']" />
        <x-ui.input label="To" name="to" type="date" :value="$filters['to']" />
        <div class="flex gap-2 sm:col-span-2 lg:col-span-6">
            <x-ui.button type="submit">Filter</x-ui.button>
            @if ($hasFilters)
                <x-ui.button :href="route('owner.payments.index')" variant="ghost">Clear</x-ui.button>
            @endif
            <p class="ml-auto self-center text-xs text-app-text-2">Dates are the shop’s calendar days (Philippine time).</p>
        </div>
    </form>

    <div class="mt-6 grid items-start gap-6 2xl:grid-cols-12">
        <section class="min-w-0 2xl:col-span-8" aria-labelledby="ledger-title">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="ledger-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="receipt" :size="22" class="text-brand" />Counter payments</h2>
                <span class="font-mono text-xs text-app-text-2">{{ $payments->total() }} {{ Str::plural('record', $payments->total()) }} · newest first</span>
            </div>

            @if ($payments->isEmpty())
                <x-ui.card>
                    @if ($hasFilters)
                        <x-ui.empty-state icon="search_off" title="No payments found" description="Nothing matches your search or filters. Try other words or dates, or clear the filters.">
                            <x-ui.button :href="route('owner.payments.index')" variant="outline">Clear filters</x-ui.button>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="point_of_sale" title="No payments recorded yet" description="Payments appear here once you record them on an order or a booking." />
                    @endif
                </x-ui.card>
            @else
                <div class="hidden overflow-x-auto rounded-card border border-app-border bg-app-card shadow-[0_1px_2px_rgba(0,0,0,0.04)] dark:shadow-none xl:block">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Payments, newest first</caption>
                        <thead class="bg-neutral-50 text-[11px] uppercase tracking-wider text-app-text-2 dark:bg-white/5">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-bold">Payment</th>
                                <th scope="col" class="px-4 py-3 font-bold">Received</th>
                                <th scope="col" class="px-4 py-3 font-bold">For</th>
                                <th scope="col" class="px-4 py-3 font-bold">Customer</th>
                                <th scope="col" class="px-4 py-3 text-right font-bold">Amount</th>
                                <th scope="col" class="px-4 py-3 font-bold">Method</th>
                                <th scope="col" class="px-4 py-3 font-bold">Recorded by</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-app-border">
                            @foreach ($payments as $payment)
                                <tr @class(['transition-colors hover:bg-black/[0.02] dark:hover:bg-white/[0.03]', 'opacity-70' => ! $payment->isRecorded()])>
                                    <td class="px-4 py-4">
                                        <p @class(['font-mono text-sm font-bold text-brand', 'line-through' => ! $payment->isRecorded()])>{{ $payment->reference_number }}</p>
                                        <x-ui.badge :tone="$payment->isRecorded() ? 'green' : 'gray'" class="mt-1">{{ $payment->status->label() }}</x-ui.badge>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 font-mono text-xs"><x-ui.date :value="$payment->received_at" /></td>
                                    <td class="px-4 py-4">
                                        @if ($payment->payable)
                                            <a href="{{ $payableUrl($payment->payable) }}" class="rounded font-mono text-sm font-semibold hover:text-brand hover:underline focus-ring">{{ $payment->payable->reference_number }}</a>
                                            <p class="text-xs text-app-text-2">{{ $payment->payable instanceof PrintingOrder ? 'Printing order' : 'Photoshoot' }}</p>
                                        @else
                                            <span class="text-app-text-2">Removed record</span>
                                        @endif
                                    </td>
                                    <td class="max-w-48 px-4 py-4"><p class="truncate font-semibold" title="{{ $payment->payable?->customer?->name }}">{{ $payment->payable?->customer?->name ?? 'Deleted account' }}</p></td>
                                    <td class="whitespace-nowrap px-4 py-4 text-right font-bold tabular-nums">{{ Money::format($payment->amount) }}</td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-semibold dark:bg-white/10"><x-ui.icon :name="$payment->method->icon()" :size="14" />{{ $payment->method->label() }}</span>
                                        @if ($payment->gcash_reference)
                                            <p class="mt-1 max-w-40 break-all font-mono text-[11px] text-app-text-2">{{ $payment->gcash_reference }}</p>
                                        @endif
                                    </td>
                                    <td class="max-w-40 px-4 py-4 text-xs">
                                        <p class="truncate">{{ $payment->receiver?->name ?? 'Deleted account' }}</p>
                                        @unless ($payment->isRecorded())
                                            <p class="mt-1 text-app-text-2">Voided by {{ $payment->voider?->name ?? 'Deleted account' }}</p>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Phones and tablets: one card per payment --}}
                <ul class="space-y-3 xl:hidden">
                    @foreach ($payments as $payment)
                        <li @class(['rounded-card border border-app-border bg-app-card p-4 shadow-sm dark:shadow-none', 'opacity-75' => ! $payment->isRecorded()])>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span @class(['font-mono text-sm font-bold text-brand', 'line-through' => ! $payment->isRecorded()])>{{ $payment->reference_number }}</span>
                                <x-ui.badge :tone="$payment->isRecorded() ? 'green' : 'gray'">{{ $payment->status->label() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 break-words font-semibold">{{ $payment->payable?->customer?->name ?? 'Deleted account' }}</p>
                            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <div class="min-w-0">
                                    <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Amount</dt>
                                    <dd class="font-bold tabular-nums">{{ Money::format($payment->amount) }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Method</dt>
                                    <dd class="break-all">{{ $payment->methodText() }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Received</dt>
                                    <dd class="font-mono text-xs"><x-ui.date :value="$payment->received_at" /></dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Recorded by</dt>
                                    <dd class="break-words text-xs">{{ $payment->receiver?->name ?? 'Deleted account' }}</dd>
                                </div>
                            </dl>
                            @if ($payment->payable)
                                <a href="{{ $payableUrl($payment->payable) }}" class="mt-3 inline-flex min-h-11 items-center gap-1 rounded font-mono text-sm font-semibold text-brand hover:underline focus-ring">
                                    {{ $payment->payable->reference_number }} · {{ $payment->payable instanceof PrintingOrder ? 'Printing order' : 'Photoshoot' }}<x-ui.icon name="arrow_forward" :size="16" />
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <div class="mt-6">{{ $payments->links('pagination.simple') }}</div>
            @endif
        </section>

        <div class="min-w-0 space-y-6 2xl:col-span-4">
            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="breakdown-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="breakdown-title" class="font-display text-xl font-semibold">By method</h2>
                    <span class="font-mono text-xs text-app-text-2">{{ $period }}</span>
                </div>
                <div class="mt-5 flex flex-wrap items-center gap-6">
                    <div class="relative size-36 shrink-0">
                        {{-- Each arc sweeps in (ring-sweep); pathLength="100" makes the dash values percentages --}}
                        <svg viewBox="0 0 42 42" class="size-full -rotate-90" role="img" aria-label="Cash {{ $cashShare }} percent, GCash {{ $gcashShare }} percent of recorded payments">
                            <circle cx="21" cy="21" r="15.915" fill="none" stroke-width="5" class="stroke-neutral-200 dark:stroke-white/10" />
                            @if ($totals['cents'] > 0)
                                <circle cx="21" cy="21" r="15.915" fill="none" stroke-width="5" pathLength="100" class="animate-ring-sweep stroke-brand" stroke-dasharray="{{ $cashShare }} {{ 100 - $cashShare }}" />
                                <circle cx="21" cy="21" r="15.915" fill="none" stroke-width="5" pathLength="100" class="animate-ring-sweep stroke-ink dark:stroke-white" stroke-dasharray="{{ $gcashShare }} {{ 100 - $gcashShare }}" stroke-dashoffset="-{{ $cashShare }}" />
                            @endif
                        </svg>
                        <div class="absolute inset-0 grid place-items-center text-center">
                            <div>
                                <p class="text-sm font-bold tabular-nums">{{ $totals['count'] }}</p>
                                <p class="text-[10px] uppercase tracking-wider text-app-text-2">{{ Str::plural('payment', $totals['count']) }}</p>
                            </div>
                        </div>
                    </div>
                    <dl class="min-w-0 flex-1 space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-brand" aria-hidden="true"></span>Cash</dt>
                            <dd class="text-right font-semibold tabular-nums">{{ $peso($cash['cents']) }} <span class="text-xs font-normal text-app-text-2">({{ $cashShare }}%)</span></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-ink dark:bg-white" aria-hidden="true"></span>GCash</dt>
                            <dd class="text-right font-semibold tabular-nums">{{ $peso($gcash['cents']) }} <span class="text-xs font-normal text-app-text-2">({{ $gcashShare }}%)</span></dd>
                        </div>
                    </dl>
                </div>
                <p class="mt-4 text-xs text-app-text-2">Recorded payments only. Voided records and prices not yet paid are not counted.</p>
            </section>

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="awaiting-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="awaiting-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="pending_actions" :size="22" class="text-status-red" />Awaiting payment</h2>
                    <span class="font-mono text-xs text-app-text-2">{{ $awaiting['count'] }} · {{ $peso($awaiting['cents']) }}</span>
                </div>
                <p class="mt-1 text-xs text-app-text-2">Confirmed prices with no payment recorded yet. Open one to record its payment.</p>

                @if ($awaiting['orders']->isEmpty() && $awaiting['bookings']->isEmpty())
                    <p class="mt-4 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">Nothing is waiting for payment.</p>
                @else
                    <ul class="mt-4 divide-y divide-app-border">
                        @foreach ($awaiting['bookings'] as $booking)
                            <li>
                                <a href="{{ route('owner.bookings.show', $booking) }}#payment" class="flex min-h-11 items-center justify-between gap-3 rounded py-2.5 hover:text-brand focus-ring">
                                    <span class="min-w-0">
                                        <span class="block font-mono text-sm font-semibold">{{ $booking->reference_number }} <span class="font-sans text-xs font-normal text-app-text-2">· Photoshoot <x-ui.date :value="$booking->starts_at" format="M j" /></span></span>
                                        <span class="block truncate text-xs text-app-text-2">{{ $booking->customer?->name ?? 'Deleted account' }}</span>
                                    </span>
                                    <span class="shrink-0 font-semibold tabular-nums">{{ Money::format($booking->confirmed_price) }}</span>
                                </a>
                            </li>
                        @endforeach
                        @foreach ($awaiting['orders'] as $order)
                            <li>
                                <a href="{{ route('owner.orders.show', $order) }}#payment" class="flex min-h-11 items-center justify-between gap-3 rounded py-2.5 hover:text-brand focus-ring">
                                    <span class="min-w-0">
                                        <span class="block font-mono text-sm font-semibold">{{ $order->reference_number }} <span class="font-sans text-xs font-normal text-app-text-2">· {{ $order->status->label() }}</span></span>
                                        <span class="block truncate text-xs text-app-text-2">{{ $order->customer?->name ?? 'Deleted account' }}</span>
                                    </span>
                                    <span class="shrink-0 font-semibold tabular-nums">{{ Money::format($order->confirmed_price) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($awaiting['count'] > $awaiting['orders']->count() + $awaiting['bookings']->count())
                        <p class="mt-3 text-xs text-app-text-2">Showing the first {{ $awaiting['orders']->count() + $awaiting['bookings']->count() }} of {{ $awaiting['count'] }}. Use the order and booking lists for the rest.</p>
                    @endif
                @endif
            </section>
        </div>
    </div>
@endsection
