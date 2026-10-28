@props(['booking'])

{{--
    The session time, as in the design's "timeslot" card: what the customer first asked for, the time the studio
    proposed (while it waits for an answer), and the agreed time once confirmed. Requested and confirmed are always
    shown apart. All in the shop's time zone through <x-ui.date>.
--}}
@php
    use App\Enums\BookingStatus;

    $status = $booking->status;
    $agreed = in_array($status, [BookingStatus::Confirmed, BookingStatus::Completed], true);
    $moved = ! $booking->requested_starts_at->eq($booking->starts_at);
    $minutes = $booking->durationMinutes();
@endphp

<div {{ $attributes->class('space-y-3') }}>
    <div class="rounded-card bg-neutral-100 p-4 dark:bg-white/5">
        <div class="flex items-center justify-between gap-2">
            <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Requested time</p>
            @if ($moved || $status === BookingStatus::Proposed)
                <span class="font-mono text-[11px] uppercase text-app-text-2 line-through">First ask</span>
            @endif
        </div>
        <p class="mt-1 font-semibold"><x-ui.date :value="$booking->requested_starts_at" format="D, M j, Y" /></p>
        <p class="font-mono text-sm text-app-text-2"><x-ui.date :value="$booking->requested_starts_at" format="g:i A" /> – <x-ui.date :value="$booking->requested_starts_at->copy()->addMinutes($minutes)" format="g:i A" /></p>
    </div>

    @if ($status === BookingStatus::Proposed && $booking->proposed_starts_at)
        <div class="flex justify-center text-app-text-2" aria-hidden="true"><x-ui.icon name="arrow_downward" :size="18" /></div>
        <div class="rounded-card border border-status-blue/30 bg-status-blue-bg p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-status-blue">Proposed by the studio</p>
            <p class="mt-1 text-lg font-semibold"><x-ui.date :value="$booking->proposed_starts_at" format="D, M j, Y" /></p>
            <p class="font-mono text-sm"><x-ui.date :value="$booking->proposed_starts_at" format="g:i A" /> – <x-ui.date :value="$booking->proposed_starts_at->copy()->addMinutes($minutes)" format="g:i A" /></p>
        </div>
    @endif

    @if ($agreed)
        <div class="flex justify-center text-app-text-2" aria-hidden="true"><x-ui.icon name="arrow_downward" :size="18" /></div>
        <div class="rounded-card border border-brand/20 bg-brand/5 p-4">
            <div class="flex items-center justify-between gap-2">
                <p class="text-[11px] font-bold uppercase tracking-wider text-brand">Confirmed time</p>
                @unless ($moved)
                    <span class="rounded bg-status-green-bg px-1.5 py-0.5 text-[10px] font-bold uppercase text-status-green">Matches the request</span>
                @endunless
            </div>
            <p class="mt-1 text-lg font-semibold"><x-ui.date :value="$booking->starts_at" format="D, M j, Y" /></p>
            <p class="font-mono text-sm text-brand"><x-ui.date :value="$booking->starts_at" format="g:i A" /> – <x-ui.date :value="$booking->ends_at" format="g:i A" /></p>
        </div>
    @endif

    <p class="flex items-center gap-1.5 text-xs text-app-text-2"><x-ui.icon name="schedule" :size="14" />Philippine time · {{ $minutes }} minutes</p>
</div>
