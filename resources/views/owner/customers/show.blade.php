@extends('layouts.owner')

@section('title', $customer->name)

{{--
    One customer (Phase 6A), read only, after the profile panel of design/admin/customers_client_directory_profiles:
    contact details, counts, recorded payments and awaiting payment, then their orders and photoshoots (removed ones
    too, unlinked) and their payments. No notes, SMS, email, "new booking" or "record payment" buttons here: payments
    are recorded on the order or booking itself.
--}}
@php
    use App\Models\PrintingOrder;
    use App\Support\Money;

    $peso = fn (int $cents) => Money::format(Money::fromCents($cents));
    $title = fn ($request) => $request instanceof PrintingOrder ? $request->serviceName() : ($request->snapshot['package']['name'] ?? $request->package?->name ?? 'Photoshoot');
    $url = fn ($request) => $request instanceof PrintingOrder ? route('owner.orders.show', $request) : route('owner.bookings.show', $request);
    $requests = $data['requests'];
    $payments = $data['payments'];
@endphp

@section('main')
    <a href="{{ route('owner.customers.index') }}" class="inline-flex min-h-11 items-center gap-1 rounded text-sm font-semibold text-app-text-2 hover:text-brand focus-ring"><x-ui.icon name="arrow_back" :size="18" />All customers</a>

    <div class="mt-2 rounded-card border border-app-border bg-app-card p-5 shadow-sm sm:p-6 dark:shadow-none">
        <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 rounded-full bg-brand" aria-hidden="true"></span>Customer</p>
        <h1 class="mt-2 break-words font-display text-3xl font-semibold tracking-tight sm:text-4xl">{{ $customer->name }}</h1>
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
            <div class="min-w-0">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Email</dt>
                <dd class="break-all">{{ $customer->email }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Phone</dt>
                <dd class="break-all font-mono">{{ $customer->phone ?: '—' }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Joined</dt>
                <dd><x-ui.date :value="$customer->created_at" format="M j, Y" /></dd>
            </div>
        </dl>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-tile label="Printing orders">
            <x-slot:aside><x-ui.icon name="print" :size="20" class="text-app-text-2" /></x-slot:aside>
            <p class="mt-4 text-2xl font-bold tabular-nums">{{ $data['orders'] }}</p>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Photography bookings" :delay="60">
            <x-slot:aside><x-ui.icon name="photo_camera" :size="20" class="text-app-text-2" /></x-slot:aside>
            <p class="mt-4 text-2xl font-bold tabular-nums">{{ $data['bookings'] }}</p>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Recorded payments" :delay="120" glow>
            <x-slot:aside><x-ui.icon name="account_balance_wallet" :size="20" class="text-brand" /></x-slot:aside>
            <div class="mt-4">
                <p class="text-2xl font-bold tabular-nums">{{ $peso($data['recorded']['cents']) }}</p>
                <p class="mt-1 text-xs text-app-text-2">{{ $data['recorded']['count'] }} {{ Str::plural('payment', $data['recorded']['count']) }} · voided ones not counted</p>
            </div>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Awaiting payment" :delay="180">
            <x-slot:aside><x-ui.icon name="pending_actions" :size="20" class="text-status-red" /></x-slot:aside>
            <div class="mt-4">
                <p @class(['text-2xl font-bold tabular-nums', 'text-status-red' => $data['awaitingCents'] > 0])>{{ $peso($data['awaitingCents']) }}</p>
                <p class="mt-1 text-xs text-app-text-2">Confirmed prices not paid yet</p>
            </div>
        </x-ui.stat-tile>
    </div>

    <div class="mt-6 grid items-start gap-6 2xl:grid-cols-12">
        <section class="min-w-0 2xl:col-span-7" aria-labelledby="requests-title">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="requests-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="history" :size="22" class="text-brand" />Orders and photoshoots</h2>
                <span class="font-mono text-xs text-app-text-2">{{ $requests->total() }} · newest first</span>
            </div>

            @if ($requests->total() === 0)
                <x-ui.card><x-ui.empty-state icon="history" title="No requests yet" description="This customer has not sent a printing order or a photoshoot request." /></x-ui.card>
            @elseif ($requests->isEmpty())
                <p class="rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">This page is past the end of the list. <a href="{{ $requests->url(1) }}" class="rounded font-semibold text-brand hover:underline focus-ring">Go to the first page</a></p>
            @else
                <ul class="space-y-3">
                    @foreach ($requests as $request)
                        @php($isOrder = $request instanceof PrintingOrder)
                        <li class="rounded-card border border-app-border bg-app-card p-4 shadow-sm dark:shadow-none">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="rounded bg-neutral-100 px-2 py-0.5 font-mono text-xs font-bold dark:bg-white/10">{{ $request->reference_number }}</span>
                                    <span class="text-[11px] font-semibold uppercase tracking-wider text-app-text-2">{{ $isOrder ? 'Printing' : 'Photography' }}</span>
                                </span>
                                <span class="flex flex-wrap gap-1">
                                    @if ($request->trashed())
                                        <x-ui.badge tone="gray">Removed</x-ui.badge>
                                    @endif
                                    <x-ui.status-badge :status="$request->status" /><x-payment.badge :payable="$request" />
                                </span>
                            </div>
                            <p class="mt-2 break-words font-semibold">{{ Str::limit($title($request), 120) }}</p>
                            <div class="mt-2 flex flex-wrap items-end justify-between gap-3 text-sm">
                                <span class="text-app-text-2">Sent <x-ui.date :value="$request->created_at" format="M j, Y" /></span>
                                <span class="text-right"><span class="block text-[11px] uppercase tracking-wider text-app-text-2">{{ $request->confirmed_price !== null ? 'Confirmed price' : 'Estimate' }}</span><span class="font-bold tabular-nums">{{ $request->confirmedText() ?? $request->estimateText() }}</span></span>
                            </div>
                            @if ($request->trashed())
                                <p class="mt-2 text-xs text-app-text-2">This record was removed, so it has no page to open.</p>
                            @else
                                <a href="{{ $url($request) }}" class="mt-2 inline-flex min-h-11 items-center gap-1 rounded text-sm font-semibold text-brand hover:underline focus-ring">Open {{ $isOrder ? 'order' : 'booking' }}<x-ui.icon name="arrow_forward" :size="16" /></a>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4">{{ $requests->links('pagination.simple') }}</div>
            @endif
        </section>

        <section class="min-w-0 2xl:col-span-5" aria-labelledby="payments-title">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="payments-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="receipt" :size="22" class="text-brand" />Payments</h2>
                <span class="font-mono text-xs text-app-text-2">{{ $payments->total() }} · newest first</span>
            </div>

            @if ($payments->total() === 0)
                <x-ui.card><x-ui.empty-state icon="point_of_sale" title="No payments recorded" description="Payments appear here once they are recorded on this customer’s order or booking." /></x-ui.card>
            @elseif ($payments->isEmpty())
                <p class="rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">This page is past the end of the list. <a href="{{ $payments->url(1) }}" class="rounded font-semibold text-brand hover:underline focus-ring">Go to the first page</a></p>
            @else
                <ul class="space-y-3">
                    @foreach ($payments as $payment)
                        <li @class(['rounded-card border border-app-border bg-app-card p-4 shadow-sm dark:shadow-none', 'opacity-75' => ! $payment->isRecorded()])>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span @class(['font-mono text-sm font-bold text-brand', 'line-through' => ! $payment->isRecorded()])>{{ $payment->reference_number }}</span>
                                <x-ui.badge :tone="$payment->isRecorded() ? 'green' : 'gray'">{{ $payment->status->label() }}</x-ui.badge>
                            </div>
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
                                    <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">For</dt>
                                    <dd class="font-mono text-xs">{{ $payment->payable?->reference_number ?? 'Removed record' }}</dd>
                                </div>
                            </dl>
                            @if ($payment->payable && ! $payment->payable->trashed())
                                <a href="{{ $url($payment->payable) }}#payment" class="mt-2 inline-flex min-h-11 items-center gap-1 rounded text-sm font-semibold text-brand hover:underline focus-ring">Open {{ $payment->payable instanceof PrintingOrder ? 'order' : 'booking' }}<x-ui.icon name="arrow_forward" :size="16" /></a>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4">{{ $payments->links('pagination.simple') }}</div>
            @endif
        </section>
    </div>
@endsection
