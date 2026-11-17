{{--
    One booking on the calendar, linking to its page: confirmed sessions solid (as in the design), completed ones
    solid grey, requests and proposals outlined with a dashed border so they never look booked.
--}}
@php
    $status = $booking->status->value;
    // Phase 10D: a request or proposal that HOLDS its time (the one 10A holding rule) is marked "Held request"
    $held = in_array($status, ['requested', 'proposed'], true) && $booking->holdsTime();
    $label = $held ? ($status === 'proposed' ? 'Held: time offered' : 'Held request') : $booking->statusLabel();
    $style = match ($status) {
        'confirmed' => 'bg-ink text-white hover:bg-neutral-800 dark:bg-white dark:text-ink dark:hover:bg-neutral-200',
        'completed' => 'bg-neutral-200 text-app-text-2 hover:bg-neutral-300 dark:bg-white/15 dark:hover:bg-white/20',
        'proposed' => 'border-dashed border-status-blue bg-status-blue-bg/60 text-status-blue hover:bg-status-blue-bg '.($held ? 'border-2' : 'border opacity-80'),
        // Phase 10B: held until the downpayment deadline, not booked for good yet
        'awaiting_downpayment' => 'border-2 border-dotted border-status-violet bg-status-violet-bg/60 text-status-violet hover:bg-status-violet-bg',
        // 9C contrast: the darker amber text in light mode (the token alone is 3.2:1 on this tint)
        default => 'border-dashed border-status-amber bg-status-amber-bg/60 text-[#92400e] hover:bg-status-amber-bg dark:text-status-amber '.($held ? 'border-2' : 'border opacity-80'),
    };
@endphp
<a href="{{ route('owner.bookings.show', $booking) }}"
    class="block rounded-lg px-2 py-1 text-left text-[11px] leading-snug transition-[background-color,transform] duration-200 hover:scale-[1.02] focus-ring motion-reduce:hover:scale-100 {{ $style }}"
    title="{{ $booking->reference_number }} · {{ $label }}{{ $held ? ' (holds this time)' : '' }}{{ $booking->isWalkIn() ? ' · Walk-in' : '' }}">
    <span class="flex items-center gap-1 font-mono font-bold">
        <x-ui.date :value="$booking->starts_at" format="g:i A" />
        @unless (in_array($status, ['confirmed', 'completed'], true))<span class="inline-flex items-center gap-0.5 font-sans font-semibold normal-case">· @if ($held || $status === 'awaiting_downpayment')<x-ui.icon name="lock_clock" :size="12" />@endif{{ $label }}</span>@else<span class="sr-only">· {{ $label }}</span>@endunless
    </span>
    <span class="block truncate font-semibold">{{ $booking->customer?->name ?? 'Deleted account' }}@if ($booking->isWalkIn())<span class="font-normal"> · Walk-in</span>@endif</span>
    <span class="block truncate opacity-80">{{ $booking->snapshot['package']['name'] ?? $booking->package?->name }}@if ($full ?? false) · {{ $booking->reference_number }}@endif</span>
</a>
