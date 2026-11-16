@props(['payable'])

{{--
    Phase 10B: "How to pay" on a customer's order or booking that has something due now. There is no online payment:
    the customer pays at the shop (cash or GCash), or sends GCash to the shop's number and sends the reference in
    Messages; the studio records the payment after checking it. The button only types a draft in the Studio chat
    ("Payment for PB-000123, GCash ref: "); the customer sends it. Nothing is recorded from here.
--}}
@php
    use App\Enums\BookingStatus;
    use App\Enums\PaymentKind;
    use App\Models\PrintingOrder;
    use App\Support\LocalTime;
    use App\Support\Money;
    use App\Support\ShopSettings;

    $isOrder = $payable instanceof PrintingOrder;
    $options = $payable->paymentOptions();
    $awaiting = ! $isOrder && $payable->status === BookingStatus::AwaitingDownpayment;
    $due = match (true) {
        $awaiting => $options[PaymentKind::Downpayment->value] ?? null,
        isset($options[PaymentKind::Balance->value]) => $options[PaymentKind::Balance->value],
        default => $options[PaymentKind::Full->value] ?? null,
    };
    $deadline = $awaiting ? $payable->downpayment_due_at : null;
    $when = match (true) {
        $awaiting => 'to keep your time',
        $isOrder && $payable->requires_prepayment && $payable->status->value === 'accepted' => 'before we print your order',
        $isOrder => 'when you pick up your order',
        default => 'on the day of your photoshoot',
    };
    $shop = app(ShopSettings::class);
    $gcash = trim(($shop->gcashName() ?? '').' '.($shop->gcashNumber() ?? ''));
    $type = $isOrder ? 'printing_order' : 'photography_booking';
@endphp

@if ($due !== null)
    <section id="how-to-pay" {{ $attributes->class('scroll-mt-24 rounded-card border border-brand/30 bg-brand/5 p-4 sm:p-5') }} aria-labelledby="how-to-pay-title">
        <h2 id="how-to-pay-title" class="flex items-center gap-2 font-display text-lg font-semibold"><x-ui.icon name="payments" :size="20" class="text-brand" />How to pay</h2>
        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
            <div class="rounded-control bg-app-card p-3 dark:bg-white/5">
                <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">{{ $awaiting ? 'Downpayment due now' : 'Amount due' }}</dt>
                <dd class="mt-1 text-2xl font-bold tabular-nums text-brand dark:text-[#ffb4ac]">{{ Money::cents($due) }}</dd>
                <dd class="text-xs text-app-text-2">{{ ucfirst($when) }}.</dd>
            </div>
            @if ($deadline)
                <div class="rounded-control bg-app-card p-3 dark:bg-white/5">
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Pay by</dt>
                    <dd class="mt-1 font-semibold"><x-ui.date :value="$deadline" /></dd>
                    <dd class="text-xs text-app-text-2">If it is not paid by then, the booking is cancelled and the time is freed.</dd>
                </div>
            @endif
        </dl>
        <p class="mt-3 text-sm">
            Pay at the {{ $isOrder ? 'shop' : 'studio' }} (cash or GCash){!! $gcash !== '' ? ', or send GCash to <strong class="font-semibold">'.e($gcash).'</strong>' : '' !!} and send the reference number in Messages. The studio records your payment after checking it.
        </p>
        @if ($awaiting)
            <p class="mt-2 text-xs text-app-text-2">The balance is paid on the day of the shoot. The downpayment is non-refundable if you cancel.</p>
        @endif
        <x-chat.message-studio :record="$payable" :type="$type" :draft="'Payment for '.$payable->reference_number.', GCash ref: '"
            class="mt-3 inline-flex min-h-11 items-center gap-1.5 rounded-control border border-brand/40 px-3 text-sm font-semibold text-brand hover:bg-brand/10 focus-ring dark:text-[#ffb4ac]">
            <x-ui.icon name="forum" :size="18" />Send the GCash reference in Messages
        </x-chat.message-studio>
    </section>
@endif
