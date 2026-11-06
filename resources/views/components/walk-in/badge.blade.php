@props(['record'])

{{-- "Walk-in" next to an order, booking or customer made at the counter (Phase 8A); nothing for online ones. --}}
@if ($record?->isWalkIn())
    <x-ui.badge tone="blue" :dot="false" {{ $attributes }}><x-ui.icon name="storefront" :size="12" class="-ml-0.5" />Walk-in</x-ui.badge>
@endif
