@extends('layouts.customer')

@section('title', 'Request sent '.$booking->reference_number.' | '.config('app.name'))

@php
    $snapshot = $booking->snapshot ?? [];
    $packageName = $snapshot['package']['name'] ?? $booking->package?->name;
    $priceText = $snapshot['package']['price_text'] ?? $booking->estimateText();
    $themeName = $snapshot['theme']['name'] ?? null;
@endphp

@section('main')
    {{-- The success card of the booking design, as its own page: it fades in while growing from 95% --}}
    <div class="mx-auto max-w-2xl">
        <div class="animate-pop-in rounded-card border border-app-border bg-app-card p-5 shadow-[0_12px_24px_-6px_rgba(23,23,23,0.08),0_4px_8px_-2px_rgba(23,23,23,0.03)] dark:shadow-none sm:p-8">
            <div class="flex items-center gap-4">
                <div class="grid size-12 shrink-0 place-items-center rounded-card bg-brand text-white shadow-md" aria-hidden="true">
                    <x-ui.icon name="mark_email_read" :size="28" />
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-brand">Request sent</p>
                    <h1 class="font-display text-2xl font-semibold sm:text-3xl">Your photoshoot request was sent</h1>
                </div>
            </div>
            <p class="mt-3 text-sm text-app-text-2">The studio will review it and confirm the time, offer another time, or let you know if it cannot take it. Sending a request does not confirm the booking yet.</p>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-card bg-neutral-100 p-4 dark:bg-white/5">
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Reference number</p>
                    <p class="font-mono text-xl font-bold tracking-wider">{{ $booking->reference_number }}</p>
                </div>
                <div class="relative" x-data="copyText(@js($booking->reference_number))" x-cloak>
                    <button type="button" x-on:click="copy()"
                        class="inline-flex min-h-11 items-center gap-1.5 rounded-lg bg-app-card px-3 text-sm font-semibold shadow-sm transition-colors hover:bg-neutral-50 focus-ring dark:bg-white/10 dark:hover:bg-white/15 sm:min-h-9">
                        <x-ui.icon name="content_copy" :size="16" /><span x-text="copied ? 'Copied!' : 'Copy reference'">Copy reference</span>
                    </button>
                    <span class="pointer-events-none absolute -top-8 right-0 rounded bg-ink px-2 py-0.5 text-[10px] font-bold uppercase text-white shadow transition-opacity duration-200 dark:bg-white dark:text-ink"
                        x-bind:class="copied ? 'opacity-100' : 'opacity-0'" aria-hidden="true">Copied!</span>
                    <span class="sr-only" role="status" x-text="copied ? 'Reference number copied' : ''"></span>
                </div>
            </div>
            <p class="mt-2 text-xs text-app-text-2">Keep this number. Use it when you ask the studio about your booking.</p>

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 [&>div]:min-w-0">
                <div class="sm:col-span-2">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Package</dt>
                    <dd class="mt-0.5 break-words font-semibold">{{ $packageName }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Status</dt>
                    <dd class="mt-1"><x-ui.status-badge :status="$booking->status" live /></dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Requested time (Philippine time)</dt>
                    <dd class="mt-0.5 font-semibold"><x-ui.date :value="$booking->starts_at" format="D, M j, Y" /></dd>
                    <dd class="text-app-text-2"><x-ui.date :value="$booking->starts_at" format="g:i A" /> – <x-ui.date :value="$booking->ends_at" format="g:i A" /></dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Number of people</dt>
                    <dd class="mt-0.5 tabular-nums">{{ $booking->group_size }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Prints</dt>
                    <dd class="mt-0.5">{{ $booking->wants_prints ? 'I may want prints' : 'Digital only' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Backdrop</dt>
                    <dd class="mt-0.5 break-words">{{ $themeName ?? 'No listed backdrop' }}</dd>
                    @if ($booking->custom_theme_text)
                        <dd class="mt-0.5 whitespace-pre-line break-words text-app-text-2">{{ $booking->custom_theme_text }}</dd>
                    @endif
                </div>
                @if ($booking->images->isNotEmpty())
                    <div class="sm:col-span-2">
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Reference images ({{ $booking->images->count() }})</dt>
                        <dd class="mt-0.5">
                            <ul class="space-y-0.5">
                                @foreach ($booking->images as $image)
                                    <li class="truncate">{{ $image->original_name }}</li>
                                @endforeach
                            </ul>
                        </dd>
                    </div>
                @endif
                <div class="border-t border-app-border pt-4 sm:col-span-2">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Estimated price</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-brand {{ $booking->estimated_price === null ? 'text-base' : 'text-2xl' }}">{{ $priceText }}</dd>
                    <dd class="mt-1 text-xs text-app-text-2">Estimate only. The studio confirms the final price after reviewing your request.</dd>
                </div>
            </dl>

            <section class="mt-6 rounded-card border border-app-border p-4" aria-labelledby="next-title">
                <h2 id="next-title" class="font-semibold">What happens next</h2>
                <ol class="mt-3 space-y-2 text-sm text-app-text-2">
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand/10 text-xs font-bold text-brand">1</span><span>The studio reviews your request and the time you asked for.</span></li>
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand/10 text-xs font-bold text-brand">2</span><span>The studio confirms the time, or offers another time for you to accept.</span></li>
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand/10 text-xs font-bold text-brand">3</span><span>You come to the studio for your shoot and pay face to face there.</span></li>
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand/10 text-xs font-bold text-brand">4</span><span>After the shoot, you can order prints separately if you want them.</span></li>
                </ol>
            </section>

            <x-ui.notice variant="payment" class="mt-4">
                <p class="text-app-text-2">Nothing is charged online. The studio confirms the final price before you pay.</p>
            </x-ui.notice>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <x-ui.button :href="route('customer.bookings.show', $booking)" class="sm:col-span-2"><x-ui.icon name="timeline" :size="18" />Follow this booking</x-ui.button>
                <x-ui.button :href="route('customer.bookings.create')" variant="outline"><x-ui.icon name="add_a_photo" :size="18" />Book another photoshoot</x-ui.button>
                <x-ui.button :href="route('customer.dashboard')" variant="outline"><x-ui.icon name="dashboard" :size="18" />Back to my dashboard</x-ui.button>
            </div>
        </div>
    </div>
@endsection
