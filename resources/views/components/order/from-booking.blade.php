@props(['order', 'viewer' => 'customer'])

{{--
    "From booking PB-000001" on a printing order that was ordered after a photoshoot. The link goes to the page this
    viewer may open (their own booking, or the owner's booking page) and is left out for anyone the booking policy
    does not allow, so an order never reveals someone else's booking.
--}}
@php $booking = $order->booking; @endphp
@if ($booking && auth()->user()?->can('view', $booking))
    <p {{ $attributes->class('flex flex-wrap items-center gap-1.5 text-sm') }}>
        <x-ui.icon name="photo_camera" :size="16" class="text-brand" />
        <span class="text-app-text-2">From booking</span>
        <a href="{{ $viewer === 'owner' ? route('owner.bookings.show', $booking) : route('customer.bookings.show', $booking) }}"
            class="inline-flex min-h-11 items-center rounded font-mono font-semibold text-brand hover:underline focus-ring sm:min-h-0">{{ $booking->reference_number }}</a>
        <span class="text-app-text-2">· the photography fee is separate</span>
    </p>
@endif
