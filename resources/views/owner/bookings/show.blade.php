@extends('layouts.owner')

@section('title', 'Booking '.$booking->reference_number)

@php
    use App\Enums\BookingStatus as Status;
    use App\Services\BookingTransitionService;

    $moveValues = $moves->map(fn ($move) => $move->value)->all();
    $openForm = old('_form');
    $customer = $booking->customer;
    $packageName = $booking->snapshot['package']['name'] ?? $booking->package?->name;
    $onRequest = $booking->estimated_price === null;
    $noteMax = BookingTransitionService::NOTE_MAX;
    $reasonMax = BookingTransitionService::REASON_MAX;
    $blocked = $schedule !== null && ! $schedule['free'];
    $breaksRules = $schedule !== null && $schedule['rules'] !== [];
@endphp

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('owner.bookings.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Photography bookings</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">{{ $booking->reference_number }}</li>
        </ol>
    </nav>

    {{-- Header and actions --}}
    <section id="actions" class="scroll-mt-24 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="booking-title">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded bg-status-red-bg px-2 py-0.5 font-mono text-[11px] font-semibold uppercase text-brand">{{ $booking->reference_number }}</span>
                    <x-ui.status-badge :status="$booking->status" live />
                </div>
                <h1 id="booking-title" class="mt-2 break-words font-display text-2xl font-semibold leading-tight sm:text-3xl">
                    {{ $packageName }} <span class="font-normal italic text-app-text-2">— {{ $customer?->name ?? 'Deleted account' }}</span>
                </h1>
                <p class="mt-1 font-mono text-xs text-app-text-2">Sent: <x-ui.date :value="$booking->created_at" /></p>
            </div>
            <dl class="grid shrink-0 grid-cols-2 gap-2 text-sm">
                <div class="rounded-card bg-neutral-100 p-3 dark:bg-white/5">
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">{{ in_array($booking->status, [Status::Confirmed, Status::Completed], true) ? 'Confirmed time' : 'Requested time' }}</dt>
                    <dd class="mt-0.5 font-semibold"><x-ui.date :value="$booking->starts_at" format="D, M j" /></dd>
                    <dd class="font-mono text-xs text-app-text-2"><x-ui.date :value="$booking->starts_at" format="g:i A" /></dd>
                </div>
                <div class="rounded-card bg-neutral-100 p-3 dark:bg-white/5">
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">{{ $booking->confirmed_price !== null ? 'Confirmed price' : 'Estimate' }}</dt>
                    <dd class="mt-0.5 break-words font-semibold tabular-nums">{{ $booking->confirmedText() ?? $booking->estimateText() }}</dd>
                    <dd class="text-xs text-app-text-2">Paid at the studio</dd>
                </div>
            </dl>
        </div>

        @if (session('error'))
            <p class="mt-4 flex items-start gap-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span class="min-w-0 break-words">{{ session('error') }}</span></p>
        @endif

        @if ($errors->any())
            <ul class="mt-4 space-y-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert">
                @foreach ($errors->all() as $message)
                    <li class="flex items-start gap-1"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span class="min-w-0 break-words">{{ $message }}</span></li>
                @endforeach
            </ul>
        @endif

        @if ($moves->isEmpty())
            <p class="mt-4 text-sm text-app-text-2">This booking is {{ strtolower($booking->status->label()) }}. {{ $booking->status === Status::Proposed ? 'The customer is answering the time you offered; you can still reject or cancel it.' : 'Nothing more can be done with it.' }}</p>
        @endif

        <div class="mt-4 space-y-2">
            @if (in_array(Status::Confirmed->value, $moveValues, true))
                <details class="group rounded-card border border-app-border open:bg-neutral-50 dark:open:bg-white/5" @if ($openForm === 'confirm' || ($schedule && $openForm === null)) open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 text-sm font-semibold text-status-green focus-ring [&::-webkit-details-marker]:hidden">
                        <x-ui.icon name="event_available" :size="18" />Confirm the requested time
                        <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
                    </summary>
                    @if ($startedYet)
                        <p class="mx-4 mb-4 flex items-start gap-1.5 rounded-control bg-status-red-bg p-3 text-sm text-status-red"><x-ui.icon name="history" :size="18" class="mt-0.5 shrink-0" />{{ \App\Exceptions\BookingTransitionException::timePassed()->getMessage() }}</p>
                    @elseif ($blocked)
                        <p class="mx-4 mb-4 flex items-start gap-1.5 rounded-control bg-status-red-bg p-3 text-sm text-status-red"><x-ui.icon name="block" :size="18" class="mt-0.5 shrink-0" />This time clashes with a confirmed booking (see the schedule below), so it cannot be confirmed. Offer another time instead.</p>
                    @else
                        <form method="POST" action="{{ route('owner.bookings.confirm', $booking) }}" class="grid gap-3 px-4 pb-4 sm:grid-cols-2">
                            @csrf
                            <input type="hidden" name="_form" value="confirm">
                            <x-ui.input id="confirm-price" name="confirmed_price" label="Confirmed price (₱)" inputmode="decimal" :required="$onRequest"
                                value="{{ $openForm === 'confirm' ? old('confirmed_price') : ($booking->estimated_price ?? '') }}"
                                hint="{{ $onRequest ? 'This package is priced on request: enter the price.' : 'Filled in with the estimate. Change it if the price is different.' }}" />
                            <x-ui.input id="confirm-note" name="note" label="Note for the customer (optional)" maxlength="{{ $noteMax }}" value="{{ $openForm === 'confirm' ? old('note') : '' }}" />
                            @if ($breaksRules)
                                <div class="rounded-control border border-status-amber/30 bg-status-amber-bg p-3 text-sm sm:col-span-2">
                                    <p class="font-semibold">This time is outside the usual schedule:</p>
                                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                        @foreach ($schedule['rules'] as $reason)<li>{{ $reason }}</li>@endforeach
                                    </ul>
                                    <div class="mt-2">
                                        <x-ui.checkbox id="outside-schedule" name="outside_schedule" value="1" :checked="(bool) old('outside_schedule')">Confirm anyway. The history will say it was confirmed outside the usual schedule.</x-ui.checkbox>
                                    </div>
                                </div>
                            @endif
                            <div class="sm:col-span-2"><x-ui.button type="submit"><x-ui.icon name="check" :size="18" />Confirm booking</x-ui.button></div>
                        </form>
                    @endif
                </details>
            @endif

            @if ($canPropose)
                <details id="propose" class="group scroll-mt-24 rounded-card border border-app-border open:bg-neutral-50 dark:open:bg-white/5" @if ($openForm === 'propose' || request()->has('propose_from')) open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 text-sm font-semibold text-status-amber focus-ring [&::-webkit-details-marker]:hidden">
                        <x-ui.icon name="update" :size="18" />Offer another time
                        <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
                    </summary>
                    <div class="space-y-4 px-4 pb-4">
                        {{-- A plain GET form: works without scripts, and only reloads this page with other days --}}
                        <form method="GET" action="{{ route('owner.bookings.show', $booking) }}#propose" class="flex flex-wrap items-end gap-2">
                            <x-ui.input id="propose-from" name="propose_from" type="date" label="Show free times from" :value="$proposeFrom" />
                            <x-ui.button type="submit" variant="outline">Show times</x-ui.button>
                        </form>

                        <form method="POST" action="{{ route('owner.bookings.propose', $booking) }}" class="space-y-4">
                            @csrf
                            <input type="hidden" name="_form" value="propose">
                            @if ($proposalDays->isEmpty())
                                <p class="rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">No free times for a {{ $booking->durationMinutes() }}-minute session from this day within the booking limit. Try another day.</p>
                            @else
                                <fieldset>
                                    <legend class="mb-2 text-sm font-semibold">Free times for {{ $booking->durationMinutes() }} minutes (Philippine time)</legend>
                                    <div class="space-y-3">
                                        @foreach ($proposalDays as $daySlots)
                                            <fieldset>
                                                <legend class="mb-1.5 text-xs font-bold uppercase tracking-wider text-app-text-2">{{ $daySlots->first()->dateText() }}</legend>
                                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                                                    @foreach ($daySlots as $slot)
                                                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-app-border bg-app-card px-3 text-sm transition-colors hover:bg-neutral-100 has-checked:border-brand has-checked:ring-2 has-checked:ring-brand dark:hover:bg-white/10">
                                                            <input type="radio" name="starts_at" value="{{ $slot->value() }}" class="size-4 shrink-0 accent-brand" @checked(old('starts_at') === $slot->value())>
                                                            <span class="font-mono">{{ \App\Support\LocalTime::format($slot->startsAt, 'g:i A') }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </fieldset>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <x-ui.textarea id="propose-note" name="note" label="Note for the customer" maxlength="{{ $noteMax }}" rows="3" :required="true"
                                        hint="Up to {{ $noteMax }} characters. Say why you offer this time.">{{ $openForm === 'propose' ? old('note') : '' }}</x-ui.textarea>
                                    @if ($onRequest)
                                        <x-ui.input id="propose-price" name="confirmed_price" label="Price for this session (₱)" inputmode="decimal" :required="true"
                                            value="{{ $openForm === 'propose' ? old('confirmed_price') : '' }}" hint="This package is priced on request: the customer sees this price with the time." />
                                    @endif
                                </div>
                                <x-ui.button type="submit" variant="secondary"><x-ui.icon name="send" :size="18" />Send the new time</x-ui.button>
                            @endif
                        </form>
                    </div>
                </details>
            @endif

            @if (in_array(Status::Completed->value, $moveValues, true))
                <div class="rounded-card border border-app-border p-4">
                    @if ($startedYet)
                        <form method="POST" action="{{ route('owner.bookings.complete', $booking) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <x-ui.input id="complete-note" name="note" label="Note (optional)" maxlength="{{ $noteMax }}" class="min-w-0" />
                            <x-ui.button type="submit" variant="secondary"><x-ui.icon name="task_alt" :size="18" />Mark photoshoot completed</x-ui.button>
                        </form>
                    @else
                        <x-ui.button variant="secondary" :disabled="true" aria-describedby="complete-why"><x-ui.icon name="task_alt" :size="18" />Mark photoshoot completed</x-ui.button>
                        <p id="complete-why" class="mt-2 text-sm text-app-text-2">Available from the start of the session (<x-ui.date :value="$booking->starts_at" />).</p>
                    @endif
                </div>
            @endif

            @if (in_array(Status::Rejected->value, $moveValues, true))
                <details class="group rounded-card border border-app-border open:bg-neutral-50 dark:open:bg-white/5" @if ($openForm === 'reject') open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 text-sm font-semibold text-status-red focus-ring [&::-webkit-details-marker]:hidden">
                        <x-ui.icon name="block" :size="18" />Reject with a reason
                        <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
                    </summary>
                    <form method="POST" action="{{ route('owner.bookings.reject', $booking) }}" class="space-y-3 px-4 pb-4">
                        @csrf
                        <input type="hidden" name="_form" value="reject">
                        <x-ui.textarea id="reject-reason" name="reason" label="Reason" maxlength="{{ $reasonMax }}" rows="3" :required="true" hint="The customer sees this reason. Up to {{ number_format($reasonMax) }} characters.">{{ $openForm === 'reject' ? old('reason') : '' }}</x-ui.textarea>
                        <x-ui.button type="submit" variant="danger">Reject booking</x-ui.button>
                    </form>
                </details>
            @endif

            @if (in_array(Status::Cancelled->value, $moveValues, true))
                <details class="group rounded-card border border-app-border open:bg-neutral-50 dark:open:bg-white/5" @if ($openForm === 'cancel') open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 text-sm font-semibold text-app-text-2 focus-ring [&::-webkit-details-marker]:hidden">
                        <x-ui.icon name="cancel" :size="18" />Cancel booking
                        <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
                    </summary>
                    <form method="POST" action="{{ route('owner.bookings.cancel', $booking) }}" class="space-y-3 px-4 pb-4">
                        @csrf
                        <input type="hidden" name="_form" value="cancel">
                        <x-ui.textarea id="cancel-reason" name="reason" label="Reason (optional)" maxlength="{{ $noteMax }}" rows="2" hint="The customer sees it in the booking.">{{ $openForm === 'cancel' ? old('reason') : '' }}</x-ui.textarea>
                        <x-ui.button type="submit" variant="danger">Cancel booking {{ $booking->reference_number }}</x-ui.button>
                    </form>
                </details>
            @endif
        </div>
    </section>

    {{-- The schedule panel (the design's conflict engine), for a request waiting for the studio --}}
    @if ($schedule)
        <section class="mt-6 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="schedule-title">
            <h2 id="schedule-title" class="flex items-center gap-2 font-display text-xl font-semibold">
                <span @class(['size-2.5 rounded-full', 'animate-pulse bg-status-red' => $blocked, 'animate-pulse bg-status-amber' => ! $blocked && ($breaksRules || $schedule['competing']->isNotEmpty()), 'bg-status-green' => ! $blocked && ! $breaksRules && $schedule['competing']->isEmpty()]) aria-hidden="true"></span>
                Schedule check
            </h2>

            <div class="mt-4 grid gap-3 lg:grid-cols-3">
                <div @class(['rounded-card border p-4', 'border-status-red/30 bg-status-red-bg' => $blocked, 'border-app-border bg-neutral-50 dark:bg-white/5' => ! $blocked])>
                    <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider {{ $blocked ? 'text-status-red' : 'text-app-text-2' }}"><x-ui.icon :name="$blocked ? 'warning' : 'check_circle'" :size="16" />Confirmed bookings at this time</p>
                    @if ($schedule['blocking']->isEmpty())
                        <p class="mt-2 text-sm">None. The time is free.</p>
                    @else
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($schedule['blocking'] as $other)
                                <li><a href="{{ route('owner.bookings.show', $other) }}" class="font-mono font-semibold text-brand hover:underline focus-ring rounded">{{ $other->reference_number }}</a> · <x-ui.date :value="$other->starts_at" format="g:i A" />–<x-ui.date :value="$other->ends_at" format="g:i A" /></li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs {{ $blocked ? 'text-status-red' : 'text-app-text-2' }}">{{ $blocked ? 'Clashes (with the buffer). This can never be confirmed over.' : 'Still fits: the studio runs more than one session at a time.' }}</p>
                    @endif
                </div>
                <div class="rounded-card border border-app-border bg-neutral-50 p-4 dark:bg-white/5">
                    <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-app-text-2"><x-ui.icon name="group" :size="16" />Other requests for this time</p>
                    @if ($schedule['competing']->isEmpty())
                        <p class="mt-2 text-sm">None.</p>
                    @else
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($schedule['competing'] as $other)
                                <li><a href="{{ route('owner.bookings.show', $other) }}" class="font-mono font-semibold text-brand hover:underline focus-ring rounded">{{ $other->reference_number }}</a> · {{ $other->status->label() }} · <x-ui.date :value="$other->starts_at" format="g:i A" /></li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-app-text-2">Confirming this one makes the others clash.</p>
                    @endif
                </div>
                <div @class(['rounded-card border p-4', 'border-status-amber/30 bg-status-amber-bg' => $breaksRules, 'border-app-border bg-neutral-50 dark:bg-white/5' => ! $breaksRules])>
                    <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider {{ $breaksRules ? 'text-status-amber' : 'text-app-text-2' }}"><x-ui.icon name="rule" :size="16" />Schedule rules</p>
                    @if ($breaksRules)
                        <ul class="mt-2 list-disc space-y-0.5 pl-5 text-sm">
                            @foreach ($schedule['rules'] as $reason)<li>{{ $reason }}</li>@endforeach
                        </ul>
                    @else
                        <p class="mt-2 text-sm">Inside the opening hours and the booking window.</p>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-12">
        <div class="min-w-0 space-y-6 xl:col-span-7">
            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="customer-title">
                <h2 id="customer-title" class="sr-only">Customer</h2>
                <div class="flex items-center gap-4">
                    <span class="grid size-12 shrink-0 place-items-center rounded-full bg-status-red-bg font-display text-lg font-semibold text-brand" aria-hidden="true">{{ Str::upper(Str::substr($customer?->name ?? '?', 0, 2)) }}</span>
                    <div class="min-w-0">
                        <p class="break-words text-lg font-semibold">{{ $customer?->name ?? 'Deleted account' }}</p>
                        <p class="text-xs text-app-text-2">Customer</p>
                    </div>
                </div>
                <dl class="mt-4 grid gap-2 rounded-card bg-neutral-100 p-3 text-sm sm:grid-cols-2 dark:bg-white/5">
                    <div class="flex min-w-0 items-center gap-2">
                        <dt><x-ui.icon name="call" :size="18" class="text-app-text-2" /><span class="sr-only">Contact number</span></dt>
                        <dd class="min-w-0 break-words font-mono">{{ $booking->contact_number }}</dd>
                    </div>
                    @if ($customer)
                        <div class="flex min-w-0 items-center gap-2">
                            <dt><x-ui.icon name="mail" :size="18" class="text-app-text-2" /><span class="sr-only">Email</span></dt>
                            <dd class="min-w-0 truncate">{{ $customer->email }}</dd>
                        </div>
                    @endif
                </dl>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.button :href="'tel:'.preg_replace('/[^0-9+]/', '', $booking->contact_number)" variant="outline" size="sm"><x-ui.icon name="call" :size="16" />Call</x-ui.button>
                    @if ($customer)
                        <x-ui.button :href="'mailto:'.$customer->email" variant="outline" size="sm"><x-ui.icon name="mail" :size="16" />Email</x-ui.button>
                    @endif
                </div>
            </section>

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="details-title">
                <h2 id="details-title" class="font-display text-xl font-semibold">Package and moodboard</h2>
                <x-booking.details :booking="$booking" class="mt-4" />
                <h3 class="mt-6 flex items-center gap-2 text-sm font-semibold"><x-ui.icon name="photo_library" :size="18" class="text-brand" />Reference images ({{ $booking->images->count() }})</h3>
                <x-booking.images :images="$booking->images" class="mt-3" />
            </section>

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="prints-title">
                <h2 id="prints-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="print" :size="22" class="text-brand" />Printing orders from this shoot</h2>
                <p class="mt-1 text-sm text-app-text-2">Each has its own price and status; the photography fee stays separate.</p>
                <x-booking.print-orders :orders="$booking->printingOrders" viewer="owner" class="mt-3" />
            </section>
        </div>

        <div class="min-w-0 space-y-6 xl:col-span-5">
            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="time-title">
                <h2 id="time-title" class="flex items-center justify-between gap-2 font-display text-xl font-semibold">Date and time<x-ui.icon name="calendar_month" :size="22" class="text-app-text-2" /></h2>
                <x-booking.times :booking="$booking" class="mt-4" />
            </section>

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-md sm:p-6 dark:shadow-none" aria-labelledby="price-title">
                <h2 id="price-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="payments" :size="22" class="text-brand" />Price</h2>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-app-text-2">Estimate when requested</dt>
                        <dd class="text-right font-semibold tabular-nums">{{ $booking->snapshot['package']['price_text'] ?? $booking->estimateText() }}</dd>
                    </div>
                </dl>
                <div class="mt-4 flex items-baseline justify-between gap-3 rounded-card bg-neutral-100 p-4 dark:bg-white/5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Confirmed price</p>
                    <p class="text-right font-bold tabular-nums {{ $booking->confirmed_price !== null ? 'text-2xl text-brand' : 'text-app-text-2' }}">{{ $booking->confirmedText() ?? 'Not confirmed yet' }}</p>
                </div>

                @if ($canChangePrice)
                    <details class="group mt-3" @if ($openForm === 'price') open @endif>
                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-center gap-2 rounded-control bg-neutral-100 px-4 text-sm font-semibold transition-colors hover:bg-neutral-200 focus-ring dark:bg-white/10 dark:hover:bg-white/15 [&::-webkit-details-marker]:hidden">
                            <x-ui.icon name="edit" :size="18" />Change the confirmed price
                        </summary>
                        <form method="POST" action="{{ route('owner.bookings.price', $booking) }}" class="mt-3 space-y-3">
                            @csrf
                            <input type="hidden" name="_form" value="price">
                            <x-ui.input id="price-new" name="confirmed_price" label="New confirmed price (₱)" inputmode="decimal" :required="true" value="{{ $openForm === 'price' ? old('confirmed_price') : $booking->confirmed_price }}" />
                            <x-ui.input id="price-reason" name="reason" label="Reason (optional)" maxlength="{{ $noteMax }}" value="{{ $openForm === 'price' ? old('reason') : '' }}" hint="Saved in the history, which the customer can read." />
                            <x-ui.button type="submit">Save price</x-ui.button>
                        </form>
                    </details>
                @endif
                <x-ui.notice variant="payment" class="mt-4">
                    <p class="text-app-text-2">The system records payments received at the studio (cash or GCash, on the day of the shoot); it never takes payment online.</p>
                </x-ui.notice>
            </section>

            <x-payment.card :payable="$booking" viewer="owner" />

            {{-- The studio's private notes: never shown to the customer and never written to the history --}}
            <section id="internal-notes" class="scroll-mt-24 rounded-card border border-status-amber/30 bg-status-amber-bg/60 p-4 sm:p-6" aria-labelledby="notes-title">
                <h2 id="notes-title" class="flex items-center justify-between gap-2 text-sm font-bold uppercase tracking-wider text-status-amber">
                    <span class="flex items-center gap-2"><x-ui.icon name="lock" :size="18" />Internal notes</span>
                    <span class="font-mono text-[11px] font-medium normal-case">Studio only</span>
                </h2>
                <form method="POST" action="{{ route('owner.bookings.notes', $booking) }}" class="mt-3 space-y-3">
                    @csrf
                    <input type="hidden" name="_form" value="notes">
                    <x-ui.textarea id="internal-notes-text" name="internal_notes" label="Notes for the studio" maxlength="{{ BookingTransitionService::INTERNAL_NOTE_MAX }}" rows="4"
                        hint="Up to {{ number_format(BookingTransitionService::INTERNAL_NOTE_MAX) }} characters. The customer never sees these.">{{ $openForm === 'notes' ? old('internal_notes') : $booking->internal_notes }}</x-ui.textarea>
                    <x-ui.button type="submit" variant="secondary" size="sm"><x-ui.icon name="save" :size="16" />Save notes</x-ui.button>
                </form>
            </section>

            @if ($booking->rejection_reason)
                <section class="rounded-card border border-status-red/30 bg-status-red-bg/50 p-4" aria-labelledby="reason-title">
                    <h2 id="reason-title" class="flex items-center gap-2 text-sm font-semibold text-status-red"><x-ui.icon name="block" :size="18" />Reason for rejecting</h2>
                    <p class="mt-2 whitespace-pre-line break-words text-sm">{{ $booking->rejection_reason }}</p>
                </section>
            @endif

            @if ($booking->cancellation_reason)
                <section class="rounded-card border border-app-border bg-neutral-100 p-4 dark:bg-white/5" aria-labelledby="cancel-reason-title">
                    <h2 id="cancel-reason-title" class="flex items-center gap-2 text-sm font-semibold"><x-ui.icon name="cancel" :size="18" />Reason for cancelling</h2>
                    <p class="mt-2 whitespace-pre-line break-words text-sm text-app-text-2">{{ $booking->cancellation_reason }}</p>
                </section>
            @endif

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="history-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="history-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="history" :size="22" class="text-brand" />History</h2>
                    <span class="font-mono text-xs text-app-text-2">Newest first</span>
                </div>
                <x-order.timeline :histories="$booking->statusHistories" :customer-id="$booking->customer_id" viewer="owner" shop="Studio" newest-first class="mt-5" />
            </section>
        </div>
    </div>
@endsection
