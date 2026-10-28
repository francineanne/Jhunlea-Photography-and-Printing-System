@extends('layouts.customer')

@section('title', 'Order placed '.$order->reference_number.' | '.config('app.name'))

@php
    $snapshot = $order->snapshot ?? [];
    $options = collect($snapshot['options'] ?? []);
    $quoted = $order->estimated_price === null;
@endphp

@section('main')
    {{-- The success card of the wizard design, as its own page: it fades in while growing from 95% --}}
    <div class="mx-auto max-w-2xl">
        <div class="animate-pop-in rounded-card border border-app-border bg-app-card p-5 shadow-[0_12px_24px_-6px_rgba(23,23,23,0.08),0_4px_8px_-2px_rgba(23,23,23,0.03)] dark:shadow-none sm:p-8">
            <div class="mx-auto grid size-16 place-items-center rounded-full bg-brand text-white shadow-md" aria-hidden="true">
                <x-ui.icon name="check" :size="36" />
            </div>

            <div class="mt-4 text-center">
                <p class="text-[11px] font-bold uppercase tracking-widest text-brand">Order placed</p>
                <h1 class="mt-1 font-display text-2xl font-semibold sm:text-3xl">Your printing order was sent</h1>
                <p class="mx-auto mt-2 max-w-md text-sm text-app-text-2">The shop will check your files and details. Sending an order does not mean it is accepted yet.</p>
            </div>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-card bg-neutral-100 p-4 dark:bg-white/5">
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Reference number</p>
                    <p class="font-mono text-xl font-bold tracking-wider">{{ $order->reference_number }}</p>
                </div>
                <div class="relative" x-data="copyText(@js($order->reference_number))" x-cloak>
                    <button type="button" x-on:click="copy()"
                        class="inline-flex min-h-11 items-center gap-1.5 rounded-lg bg-app-card px-3 text-sm font-semibold shadow-sm transition-colors hover:bg-neutral-50 focus-ring dark:bg-white/10 dark:hover:bg-white/15 sm:min-h-9">
                        <x-ui.icon name="content_copy" :size="16" /><span x-text="copied ? 'Copied!' : 'Copy reference'">Copy reference</span>
                    </button>
                    <span class="pointer-events-none absolute -top-8 right-0 rounded bg-ink px-2 py-0.5 text-[10px] font-bold uppercase text-white shadow transition-opacity duration-200 dark:bg-white dark:text-ink"
                        x-bind:class="copied ? 'opacity-100' : 'opacity-0'" aria-hidden="true">Copied!</span>
                    <span class="sr-only" role="status" x-text="copied ? 'Reference number copied' : ''"></span>
                </div>
            </div>
            <p class="mt-2 text-xs text-app-text-2">Keep this number. Use it when you ask the shop about your order.</p>
            <x-order.from-booking :order="$order" class="mt-2" />

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 [&>div]:min-w-0">
                <div class="sm:col-span-2">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Service</dt>
                    <dd class="mt-0.5 font-semibold">{{ $snapshot['service']['name'] ?? $order->service->name }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Status</dt>
                    <dd class="mt-1"><x-ui.status-badge :status="$order->status" /></dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Sent on</dt>
                    <dd class="mt-0.5"><x-ui.date :value="$order->created_at" /></dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Copies</dt>
                    <dd class="mt-0.5 tabular-nums">{{ $order->quantity }}</dd>
                </div>
                @if (! empty($snapshot['pages']))
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Pages</dt>
                        <dd class="mt-0.5 tabular-nums">{{ $snapshot['pages'] }}</dd>
                    </div>
                @endif
                @if ($options->isNotEmpty())
                    <div class="sm:col-span-2">
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Options</dt>
                        <dd class="mt-0.5">
                            <ul class="space-y-0.5">
                                @foreach ($options as $option)
                                    <li>{{ \App\Enums\OptionGroup::tryFrom($option['group'])?->label() ?? $option['group'] }}: {{ $option['label'] }}</li>
                                @endforeach
                            </ul>
                        </dd>
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Files ({{ $order->files->count() }})</dt>
                    <dd class="mt-0.5">
                        <ul class="space-y-0.5">
                            @foreach ($order->files as $file)
                                <li class="truncate">{{ $file->original_name }}</li>
                            @endforeach
                        </ul>
                    </dd>
                </div>
                <div class="border-t border-app-border pt-4 sm:col-span-2">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Estimated price</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-brand {{ $quoted ? 'text-base' : 'text-2xl' }}">{{ $order->estimateText() }}</dd>
                    @unless ($quoted)
                        <dd class="mt-1 text-xs text-app-text-2">Estimate only. The shop confirms the final price after checking your file.</dd>
                    @endunless
                </div>
            </dl>

            <section class="mt-6 rounded-card border border-app-border p-4" aria-labelledby="next-title">
                <h2 id="next-title" class="font-semibold">What happens next</h2>
                <ol class="mt-3 space-y-2 text-sm text-app-text-2">
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand/10 text-xs font-bold text-brand">1</span><span>The shop reviews your files and details.</span></li>
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand/10 text-xs font-bold text-brand">2</span><span>If something is unclear, the shop asks you for more information.</span></li>
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand/10 text-xs font-bold text-brand">3</span><span>The shop accepts the order and confirms the final price.</span></li>
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand/10 text-xs font-bold text-brand">4</span><span>When your prints are ready, you pick them up at the shop. Payment is made face-to-face there.</span></li>
                </ol>
            </section>

            <x-ui.notice variant="payment" class="mt-4">
                <p class="text-app-text-2">Nothing is charged online. The shop confirms the final price before you pay.</p>
            </x-ui.notice>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <x-ui.button :href="route('customer.printing.show', $order)" class="sm:col-span-2"><x-ui.icon name="timeline" :size="18" />Track this order</x-ui.button>
                <x-ui.button :href="route('customer.printing.index')" variant="outline">All my orders</x-ui.button>
                <x-ui.button :href="route('customer.printing.create')" variant="outline">Place another order</x-ui.button>
            </div>
        </div>
    </div>
@endsection
