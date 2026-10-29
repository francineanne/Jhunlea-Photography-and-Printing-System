@props(['orders', 'viewer' => 'customer'])

{{--
    The printing orders started from a photoshoot (prints after the shoot). Each keeps its own status and price; the
    photography fee is never added to them, so no combined total is shown. Each is also paid on its own (Phase 5A).
--}}
@if ($orders->isEmpty())
    <p {{ $attributes->class('text-sm text-app-text-2') }}>No prints ordered from this photoshoot.</p>
@else
    <ul {{ $attributes->class('divide-y divide-app-border rounded-card border border-app-border') }}>
        @foreach ($orders as $order)
            <li class="flex flex-wrap items-center justify-between gap-2 p-3">
                <div class="min-w-0">
                    <a href="{{ $viewer === 'owner' ? route('owner.orders.show', $order) : route('customer.printing.show', $order) }}"
                        class="inline-flex min-h-11 items-center rounded font-mono text-sm font-bold text-brand hover:underline focus-ring sm:min-h-0">{{ $order->reference_number }}</a>
                    <p class="break-words text-xs text-app-text-2">{{ $order->serviceName() }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-right text-sm font-semibold tabular-nums">
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-app-text-2">{{ $order->confirmed_price !== null ? 'Confirmed' : 'Estimate' }}</span>
                        {{ $order->confirmedText() ?? $order->estimateText() }}
                    </span>
                    <span class="flex flex-col items-end gap-1"><x-ui.status-badge :status="$order->status" /><x-payment.badge :payable="$order" /></span>
                </div>
            </li>
        @endforeach
    </ul>
@endif
