@extends('layouts.owner')

@section('title', 'Walk-in order saved')

{{-- After a walk-in order and its payment are saved (Phase 8A). Its payment receipt prints from here (Phase 8B). --}}
@php
    use App\Support\Money;

    $payment = $order->recordedPayment;
@endphp

@section('main')
    <div class="mx-auto max-w-xl animate-fade-up rounded-card border border-app-border bg-app-card p-6 text-center shadow-sm dark:shadow-none">
        <span class="mx-auto grid size-14 place-items-center rounded-full bg-status-green-bg text-status-green" aria-hidden="true"><x-ui.icon name="check_circle" :size="32" /></span>
        <h1 class="mt-4 font-display text-2xl font-semibold">Order and payment saved</h1>
        <p class="mt-1 text-app-text-2">{{ $order->customer?->name }} · Walk-in</p>

        <dl class="mt-6 grid gap-3 text-left sm:grid-cols-2">
            <div class="rounded-control bg-neutral-100 p-3 dark:bg-white/5">
                <dt class="text-xs text-app-text-2">Order</dt>
                <dd class="font-mono text-lg font-bold">{{ $order->reference_number }}</dd>
            </div>
            <div class="rounded-control bg-neutral-100 p-3 dark:bg-white/5">
                <dt class="text-xs text-app-text-2">Amount paid</dt>
                <dd class="text-lg font-bold tabular-nums">{{ Money::format($payment?->amount ?? $order->confirmed_price) }}</dd>
            </div>
            <div class="rounded-control bg-neutral-100 p-3 dark:bg-white/5">
                <dt class="text-xs text-app-text-2">Method</dt>
                <dd class="font-semibold">{{ $payment?->methodText() ?? '—' }}</dd>
            </div>
            <div class="rounded-control bg-neutral-100 p-3 dark:bg-white/5">
                <dt class="text-xs text-app-text-2">Payment</dt>
                <dd class="font-mono font-semibold">{{ $payment?->reference_number ?? '—' }}</dd>
            </div>
            @if ($changeCents !== null)
                <div class="rounded-control bg-neutral-100 p-3 sm:col-span-2 dark:bg-white/5">
                    <dt class="text-xs text-app-text-2">Change given</dt>
                    <dd class="text-lg font-bold tabular-nums">{{ Money::format(Money::fromCents((int) $changeCents)) }}</dd>
                </div>
            @endif
        </dl>

        <div class="mt-6 flex flex-wrap justify-center gap-2">
            @if ($payment)
                <x-ui.button :href="route('owner.payments.receipt', $payment)"><x-ui.icon name="print" :size="18" />Print receipt</x-ui.button>
            @endif
            <x-ui.button :href="route('owner.orders.show', $order)" :variant="$payment ? 'outline' : 'primary'"><x-ui.icon name="receipt_long" :size="18" />Open the order</x-ui.button>
            <x-ui.button :href="route('owner.orders.walk-in.create')" variant="outline"><x-ui.icon name="add" :size="18" />Another walk-in order</x-ui.button>
        </div>
    </div>
@endsection
