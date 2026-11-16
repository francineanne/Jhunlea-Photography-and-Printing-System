{{--
    Phase 10B, on the counter: photoshoots waiting for their downpayment (the nearest deadline first, with the time
    left) and today's photoshoots that still have a balance (or the full price) to pay. Each row opens the counter modal
    on that booking; recording still goes through PaymentService. Nothing here is income until it is recorded.
--}}
@php
    use App\Services\Counter\CounterService;
    use App\Support\Money;

    $payLink = fn ($record) => route('owner.payments.index', ['pay' => $record->getMorphClass().':'.$record->getKey()]);
@endphp

<section id="downpayments" class="mt-6 grid scroll-mt-24 gap-6 lg:grid-cols-2" aria-label="Downpayments and today's photoshoots">
    <div class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none">
        <h2 class="flex items-center gap-2 font-display text-lg font-semibold"><x-ui.icon name="hourglass_top" :size="20" class="text-status-violet" />Awaiting downpayment
            <span class="rounded-full bg-status-violet-bg px-2 py-0.5 text-xs font-semibold tabular-nums text-status-violet">{{ $awaitingDown->count() }}</span></h2>
        @if ($awaitingDown->isEmpty())
            <p class="mt-3 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">No photoshoot is waiting for its downpayment.</p>
        @else
            <ul class="mt-3 divide-y divide-app-border">
                @foreach ($awaitingDown as $booking)
                    @php($options = $booking->paymentOptions())
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div class="min-w-0">
                            <a href="{{ route('owner.bookings.show', $booking) }}" class="font-mono text-sm font-semibold text-brand hover:underline focus-ring rounded">{{ $booking->reference_number }}</a>
                            <span class="text-sm">{{ $booking->customer?->name ?? 'Deleted account' }}</span>
                            <p class="text-xs text-app-text-2">Due <x-ui.date :value="$booking->downpayment_due_at" /> · <span class="font-semibold">{{ CounterService::timeLeft($booking) }}</span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-semibold tabular-nums">{{ isset($options['downpayment']) ? Money::cents($options['downpayment']) : '' }}</span>
                            <x-ui.button :href="$payLink($booking)" size="sm" variant="outline"><x-ui.icon name="add_card" :size="16" />Record</x-ui.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none">
        <h2 class="flex items-center gap-2 font-display text-lg font-semibold"><x-ui.icon name="photo_camera" :size="20" class="text-brand" />Shoots today with balance due
            <span class="rounded-full bg-status-amber-bg px-2 py-0.5 text-xs font-semibold tabular-nums text-[#92400e] dark:text-status-amber">{{ $balanceToday->count() }}</span></h2>
        @if ($balanceToday->isEmpty())
            <p class="mt-3 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">No photoshoot today has anything left to pay.</p>
        @else
            <ul class="mt-3 divide-y divide-app-border">
                @foreach ($balanceToday as $booking)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div class="min-w-0">
                            <a href="{{ route('owner.bookings.show', $booking) }}" class="font-mono text-sm font-semibold text-brand hover:underline focus-ring rounded">{{ $booking->reference_number }}</a>
                            <span class="text-sm">{{ $booking->customer?->name ?? 'Deleted account' }}</span>
                            <p class="text-xs text-app-text-2"><x-ui.date :value="$booking->starts_at" format="g:i A" /> · paid so far {{ Money::cents($booking->paidCents()) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-semibold tabular-nums">{{ Money::format($booking->amountDue()) }}</span>
                            <x-ui.button :href="$payLink($booking)" size="sm" variant="outline"><x-ui.icon name="add_card" :size="16" />Record</x-ui.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
