@props(['payable'])

{{-- The payment status of an order or booking, next to its status on the lists. Not shown on a closed record that was never paid. Needs `recordedPayment` loaded. --}}
{{-- Phase 10L: an expired request shows only "Expired" (nothing is due on it) --}}
@if ($payable->showsPayment() && ! ($payable instanceof \App\Models\PhotographyBooking && $payable->isExpired()))
    @php($payment = $payable->paymentStatus())
    <x-ui.badge :tone="$payment->tone()" {{ $attributes }}><span class="sr-only">Payment: </span>{{ $payment->label() }}</x-ui.badge>
@endif
