{{--
    One booking on the calendar, linking to its page: confirmed sessions solid (as in the design), completed ones
    solid grey, requests and proposals outlined with a dashed border so they never look booked.
--}}
@php
    $status = $booking->status->value;
    $style = match ($status) {
        'confirmed' => 'bg-ink text-white hover:bg-neutral-800 dark:bg-white dark:text-ink dark:hover:bg-neutral-200',
        'completed' => 'bg-neutral-200 text-app-text-2 hover:bg-neutral-300 dark:bg-white/15 dark:hover:bg-white/20',
        'proposed' => 'border border-dashed border-status-blue bg-status-blue-bg/60 text-status-blue hover:bg-status-blue-bg',
        default => 'border border-dashed border-status-amber bg-status-amber-bg/60 text-status-amber hover:bg-status-amber-bg',
    };
@endphp
<a href="{{ route('owner.bookings.show', $booking) }}"
    class="block rounded-lg px-2 py-1 text-left text-[11px] leading-snug transition-[background-color,transform] duration-200 hover:scale-[1.02] focus-ring motion-reduce:hover:scale-100 {{ $style }}"
    title="{{ $booking->reference_number }} · {{ $booking->status->label() }}">
    <span class="flex items-center gap-1 font-mono font-bold">
        <x-ui.date :value="$booking->starts_at" format="g:i A" />
        @unless (in_array($status, ['confirmed', 'completed'], true))<span class="font-sans font-semibold normal-case">· {{ $booking->status->label() }}</span>@else<span class="sr-only">· {{ $booking->status->label() }}</span>@endunless
    </span>
    <span class="block truncate font-semibold">{{ $booking->customer?->name ?? 'Deleted account' }}</span>
    <span class="block truncate opacity-80">{{ $booking->snapshot['package']['name'] ?? $booking->package?->name }}@if ($full ?? false) · {{ $booking->reference_number }}@endif</span>
</a>
