@extends('layouts.customer')

@section('title', 'Booking '.$booking->reference_number.' | '.config('app.name'))

@php
    use App\Enums\BookingStatus;
    use App\Services\BookingTransitionService;

    $packageName = $booking->snapshot['package']['name'] ?? $booking->package?->name;
    $noteMax = BookingTransitionService::NOTE_MAX;
@endphp

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('customer.dashboard') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">My dashboard</a></li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ route('customer.bookings.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">My bookings</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">{{ $booking->reference_number }}</li>
        </ol>
    </nav>

    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="min-w-0">
            <p class="font-mono text-xs font-bold uppercase tracking-wider text-brand">Booking {{ $booking->reference_number }}</p>
            <h1 class="mt-1 break-words font-display text-3xl font-semibold leading-tight sm:text-4xl">{{ $packageName }}</h1>
            <p class="mt-1 text-sm text-app-text-2">Sent on <x-ui.date :value="$booking->created_at" /></p>
            <a href="{{ route('customer.assistant.page', ['tab' => 'studio', 'about' => 'photography_booking:'.$booking->id]) }}" class="mt-1 inline-flex min-h-11 items-center gap-1.5 rounded text-sm font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac]"><x-ui.icon name="forum" :size="18" />Message the studio about this request</a>
        </div>
        <x-ui.status-badge :status="$booking->status" live class="self-start md:self-auto" />
    </header>

    {{-- A time the studio offered: the customer accepts it or asks for another (as in the design's banner) --}}
    @if ($booking->status === BookingStatus::Proposed)
        <section id="respond" class="relative mt-6 scroll-mt-28 overflow-hidden rounded-card border border-brand/20 bg-gradient-to-br from-brand/10 via-app-card to-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="proposal-title">
            <span class="absolute inset-y-0 left-0 w-1.5 bg-brand" aria-hidden="true"></span>
            <div class="flex items-start gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-card bg-brand text-white shadow-md" aria-hidden="true"><x-ui.icon name="update" :size="22" /></span>
                <div class="min-w-0">
                    <h2 id="proposal-title" class="font-semibold sm:text-lg">The studio offered a new time</h2>
                    @if ($booking->proposed_note)
                        <p class="mt-1 whitespace-pre-line break-words text-sm text-app-text-2">{{ $booking->proposed_note }}</p>
                    @endif
                </div>
            </div>

            <dl class="mt-4 grid gap-2 rounded-card bg-app-card p-3 text-sm sm:grid-cols-2 dark:bg-white/5">
                <div class="min-w-0">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">You asked for</dt>
                    <dd class="text-app-text-2 line-through"><x-ui.date :value="$booking->starts_at" /></dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-brand">New time (Philippine time)</dt>
                    <dd class="font-semibold"><x-ui.date :value="$booking->proposed_starts_at" /></dd>
                </div>
                @php $price = $booking->priceIfAccepted(); @endphp
                <div class="min-w-0 sm:col-span-2">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">If you accept · {{ $price['label'] }}</dt>
                    <dd class="font-semibold tabular-nums">{{ $price['text'] }}</dd>
                    @unless ($price['confirmed'])
                        <dd class="text-xs text-app-text-2">The studio confirms the final price.</dd>
                    @endunless
                </div>
            </dl>

            @if ($canRespond)
                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
                    <form method="POST" action="{{ route('customer.bookings.accept', $booking) }}">
                        @csrf
                        <x-ui.button type="submit" class="w-full sm:w-auto"><x-ui.icon name="event_available" :size="18" />Accept the new time</x-ui.button>
                    </form>
                    <details class="group min-w-0 flex-1 rounded-card border border-app-border bg-app-card dark:bg-white/5" @if ($errors->has('note')) open @endif>
                        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 text-sm font-semibold focus-ring [&::-webkit-details-marker]:hidden">
                            <x-ui.icon name="calendar_month" :size="18" />Ask for another time
                            <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
                        </summary>
                        <form method="POST" action="{{ route('customer.bookings.another-time', $booking) }}" class="space-y-3 px-4 pb-4">
                            @csrf
                            <x-ui.textarea id="another-note" name="note" label="Which days or times suit you?" maxlength="{{ $noteMax }}" rows="3" :required="true"
                                hint="Up to {{ $noteMax }} characters. The studio reads it and offers another time.">{{ old('note') }}</x-ui.textarea>
                            <x-ui.button type="submit" variant="secondary">Send to the studio</x-ui.button>
                        </form>
                    </details>
                </div>
            @endif
        </section>
    @endif

    @if ($booking->status === BookingStatus::Rejected && $booking->rejection_reason)
        <section class="mt-6 rounded-card border border-status-red/30 bg-status-red-bg/50 p-4 sm:p-6" aria-labelledby="rejected-title">
            <h2 id="rejected-title" class="flex items-center gap-2 font-semibold text-status-red"><x-ui.icon name="block" :size="20" />The studio could not take this booking</h2>
            <p class="mt-2 whitespace-pre-line break-words text-sm">{{ $booking->rejection_reason }}</p>
        </section>
    @endif

    @if ($booking->status === BookingStatus::Cancelled && $booking->cancellation_reason)
        <section class="mt-6 rounded-card border border-app-border bg-neutral-100 p-4 sm:p-6 dark:bg-white/5" aria-labelledby="cancelled-title">
            <h2 id="cancelled-title" class="flex items-center gap-2 font-semibold"><x-ui.icon name="cancel" :size="20" />Reason for cancelling</h2>
            <p class="mt-2 whitespace-pre-line break-words text-sm text-app-text-2">{{ $booking->cancellation_reason }}</p>
        </section>
    @endif

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-12">
        <div class="min-w-0 space-y-6 lg:col-span-7">
            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="timeline-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="timeline-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="timeline" :size="22" class="text-brand" />Progress</h2>
                    <span class="font-mono text-xs text-app-text-2">Newest first</span>
                </div>
                <x-order.timeline :histories="$booking->statusHistories" :customer-id="$booking->customer_id" viewer="customer" newest-first class="mt-5" />
            </section>

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="details-title">
                <h2 id="details-title" class="font-display text-xl font-semibold">Package and moodboard</h2>
                <x-booking.details :booking="$booking" class="mt-4" />
                <h3 class="mt-6 flex items-center gap-2 text-sm font-semibold"><x-ui.icon name="photo_library" :size="18" class="text-brand" />Reference images</h3>
                <x-booking.images :images="$booking->images" class="mt-3" />
            </section>

            <section id="prints" class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="prints-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="prints-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="print" :size="22" class="text-brand" />Prints</h2>
                    @if ($canOrderPrints)
                        <x-ui.button :href="route('customer.printing.create', ['booking' => $booking->id])" size="sm"><x-ui.icon name="add_photo_alternate" :size="18" />Order prints</x-ui.button>
                    @endif
                </div>
                <p class="mt-1 text-sm text-app-text-2">{{ $canOrderPrints ? 'Order prints of your photos. Prints are priced separately from the photography fee.' : 'Prints can be ordered once the photoshoot is completed. They are priced separately.' }}</p>
                <x-booking.print-orders :orders="$booking->printingOrders" class="mt-3" />
            </section>
        </div>

        <div class="min-w-0 space-y-6 lg:col-span-5">
            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="time-title">
                <h2 id="time-title" class="flex items-center justify-between gap-2 font-display text-xl font-semibold">Date and time<x-ui.icon name="calendar_month" :size="22" class="text-app-text-2" /></h2>
                <x-booking.times :booking="$booking" class="mt-4" />
            </section>

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-md sm:p-6 dark:shadow-none" aria-labelledby="price-title">
                <h2 id="price-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="payments" :size="22" class="text-brand" />Price</h2>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-app-text-2">Estimated price</dt>
                        <dd class="text-right font-semibold tabular-nums">{{ $booking->snapshot['package']['price_text'] ?? $booking->estimateText() }}</dd>
                    </div>
                </dl>
                <div class="mt-4 flex items-baseline justify-between gap-3 rounded-card bg-neutral-100 p-4 dark:bg-white/5">
                    <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-app-text-2"><x-ui.icon name="lock" :size="14" />Confirmed price</p>
                    <p class="text-right font-bold tabular-nums {{ $booking->confirmed_price !== null ? 'text-2xl text-brand' : 'text-sm text-app-text-2' }}">{{ $booking->confirmedText() ?? 'After the studio confirms' }}</p>
                </div>
                <x-ui.notice variant="payment" class="mt-4">
                    <p class="text-app-text-2">Nothing is charged online. You pay at the studio.</p>
                </x-ui.notice>
            </section>

            <x-payment.card :payable="$booking" />

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="cancel-title">
                <h2 id="cancel-title" class="flex items-center gap-2 font-semibold">
                    <x-ui.icon :name="$canCancel ? 'lock_open' : 'lock'" :size="20" class="text-app-text-2" />Changing or cancelling
                </h2>
                <p class="mt-2 text-sm text-app-text-2">A request cannot be edited. To change it, cancel it and send a new one.</p>

                @if ($canCancel)
                    <details class="group mt-4" @if ($errors->has('reason')) open @endif>
                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-center gap-2 rounded-control border border-status-red/30 px-4 text-sm font-semibold text-status-red transition-colors hover:bg-status-red-bg focus-ring [&::-webkit-details-marker]:hidden">
                            <x-ui.icon name="cancel" :size="18" />Cancel this booking
                        </summary>
                        <form method="POST" action="{{ route('customer.bookings.cancel', $booking) }}" class="mt-4 space-y-3 rounded-card bg-neutral-100 p-4 dark:bg-white/5">
                            @csrf
                            <x-ui.textarea id="cancel-reason" name="reason" label="Reason (optional)" maxlength="{{ $noteMax }}" rows="3" hint="Up to {{ $noteMax }} characters. The studio sees it.">{{ old('reason') }}</x-ui.textarea>
                            <x-ui.button type="submit" variant="danger">Yes, cancel booking {{ $booking->reference_number }}</x-ui.button>
                        </form>
                    </details>
                @elseif ($pastCutoff)
                    <p class="mt-3 rounded-control bg-status-amber-bg p-3 text-sm">
                        A confirmed booking can be cancelled here until {{ $cutoffHours }} hours before it starts
                        (that was {{ \App\Support\LocalTime::format($cutoffAt) }}). Please talk to the studio if you cannot come.
                    </p>
                @elseif ($booking->status->isFinal())
                    <p class="mt-3 text-sm text-app-text-2">This booking is {{ strtolower($booking->status->label()) }}, so nothing can be changed. You can always send a new request.</p>
                @endif

                <x-ui.button :href="route('customer.bookings.create')" variant="outline" class="mt-4 w-full"><x-ui.icon name="add_a_photo" :size="18" />Book another photoshoot</x-ui.button>
            </section>
        </div>
    </div>
@endsection
