@props(['payable'])

{{-- The payment status of an order or booking, next to its status on the lists. Not shown on a closed record that was never paid. Needs `recordedPayment` loaded. --}}
@if ($payable->showsPayment())
    @php($payment = $payable->paymentStatus())
    <x-ui.badge :tone="$payment->tone()" {{ $attributes }}><span class="sr-only">Payment: </span>{{ $payment->label() }}</x-ui.badge>
@endif
