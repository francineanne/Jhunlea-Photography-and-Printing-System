@props(['variant' => 'info'])

@php
    $payment = $variant === 'payment';
@endphp

<div role="note" {{ $attributes->class([
    'flex gap-3 border p-4 text-sm',
    'rounded-card' => ! $payment,
    'rounded-control border-l-4 border-l-brand!' => $payment,
    'border-status-blue/25 bg-status-blue-bg' => ! $payment,
    'border-neutral-200 bg-card dark:border-app-border dark:bg-white/5' => $payment,
]) }}>
    <x-ui.icon :name="$payment ? 'storefront' : 'info'" :size="22" class="mt-0.5 {{ $payment ? 'text-brand' : 'text-status-blue' }}" />
    <div class="min-w-0 space-y-1">
        @if ($payment)
            <p class="font-semibold">Payment is made face-to-face at Jhunlea Photography &amp; Printing. No online checkout.</p>
        @endif
        {{ $slot }}
    </div>
</div>
