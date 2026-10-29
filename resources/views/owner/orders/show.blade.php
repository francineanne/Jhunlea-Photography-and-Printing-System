@extends('layouts.owner')

@section('title', 'Order '.$order->reference_number)

@php
    use App\Enums\PrintingOrderStatus as Status;

    // Simple moves are one button each; the others open a short form (a <details> panel, so it also works without scripts)
    $simple = [
        Status::UnderReview->value => ['route' => 'owner.orders.review', 'label' => 'Start review', 'icon' => 'rate_review'],
        Status::Processing->value => ['route' => 'owner.orders.processing', 'label' => 'Mark processing', 'icon' => 'print'],
        Status::ReadyForPickup->value => ['route' => 'owner.orders.ready', 'label' => 'Mark ready for pickup', 'icon' => 'inventory_2'],
        Status::Completed->value => ['route' => 'owner.orders.complete', 'label' => 'Mark completed', 'icon' => 'task_alt'],
    ];
    $moveValues = $moves->map(fn ($move) => $move->value)->all();
    $openForm = old('_form');
    $snapshot = $order->snapshot ?? [];
    $customer = $order->customer;
@endphp

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('owner.orders.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Printing orders</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">{{ $order->reference_number }}</li>
        </ol>
    </nav>

    {{-- Header and actions --}}
    <section id="actions" class="scroll-mt-24 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="order-title">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 id="order-title" class="font-display text-2xl font-semibold sm:text-3xl">{{ $order->reference_number }}</h1>
                    <span class="rounded bg-status-red-bg px-2 py-0.5 font-mono text-[11px] font-semibold uppercase text-brand">{{ $order->serviceName() }}</span>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <x-ui.status-badge :status="$order->status" live />
                    @if ($order->needsInfo())
                        <x-ui.badge tone="amber" pulse="pulse">Waiting for the customer</x-ui.badge>
                    @endif
                </div>
                <p class="mt-2 font-mono text-xs text-app-text-2">Sent: <x-ui.date :value="$order->created_at" /></p>
                <x-order.from-booking :order="$order" viewer="owner" class="mt-1" />
            </div>

            @if ($moves->isNotEmpty() || $canRequestInfo || $canChangePrice)
                <div class="flex flex-wrap gap-2 xl:max-w-xl xl:justify-end">
                    @foreach ($simple as $value => $action)
                        @if (in_array($value, $moveValues, true))
                            <form method="POST" action="{{ route($action['route'], $order) }}">
                                @csrf
                                <x-ui.button type="submit"><x-ui.icon :name="$action['icon']" :size="18" />{{ $action['label'] }}</x-ui.button>
                            </form>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        @if ($errors->any())
            <ul class="mt-4 space-y-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert">
                @foreach ($errors->all() as $message)
                    <li class="flex items-start gap-1"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span class="min-w-0 break-words">{{ $message }}</span></li>
                @endforeach
            </ul>
        @endif

        {{-- Actions that need something typed --}}
        <div class="mt-4 space-y-2">
            @if (in_array(Status::Accepted->value, $moveValues, true))
                <details class="group rounded-card border border-app-border open:bg-neutral-50 dark:open:bg-white/5" @if ($openForm === 'accept') open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 text-sm font-semibold text-status-green focus-ring [&::-webkit-details-marker]:hidden">
                        <x-ui.icon name="check_circle" :size="18" />Accept with a confirmed price
                        <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
                    </summary>
                    <form method="POST" action="{{ route('owner.orders.accept', $order) }}" class="grid gap-3 px-4 pb-4 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="_form" value="accept">
                        <x-ui.input id="accept-price" name="confirmed_price" label="Confirmed price (₱)" inputmode="decimal" :required="true"
                            value="{{ $openForm === 'accept' ? old('confirmed_price') : ($order->estimated_price ?? '') }}"
                            hint="{{ $order->estimated_price !== null ? 'Filled in with the estimate. Change it if the final price is different.' : 'No estimate: this is a quoted service.' }}" />
                        <x-ui.input id="accept-note" name="note" label="Note for the history (optional)" maxlength="500" value="{{ $openForm === 'accept' ? old('note') : '' }}" />
                        <div class="sm:col-span-2"><x-ui.button type="submit">Accept order</x-ui.button></div>
                    </form>
                </details>
            @endif

            @if ($canRequestInfo)
                <details class="group rounded-card border border-app-border open:bg-neutral-50 dark:open:bg-white/5" @if ($openForm === 'info') open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 text-sm font-semibold text-status-amber focus-ring [&::-webkit-details-marker]:hidden">
                        <x-ui.icon name="help" :size="18" />{{ $order->needsInfo() ? 'Change the request for information' : 'Ask the customer for more information' }}
                        <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
                    </summary>
                    <form method="POST" action="{{ route('owner.orders.info', $order) }}" class="space-y-3 px-4 pb-4">
                        @csrf
                        <input type="hidden" name="_form" value="info">
                        <x-ui.textarea id="info-message" name="message" label="What do you need?" maxlength="1000" rows="3" :required="true"
                            hint="The customer sees this on their order. It is cleared when they add a file or when you move the order on.">{{ $openForm === 'info' ? old('message') : $order->info_request }}</x-ui.textarea>
                        <x-ui.button type="submit" variant="secondary">Send request</x-ui.button>
                    </form>
                </details>
            @endif

            @if (in_array(Status::Rejected->value, $moveValues, true))
                <details class="group rounded-card border border-app-border open:bg-neutral-50 dark:open:bg-white/5" @if ($openForm === 'reject') open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 text-sm font-semibold text-status-red focus-ring [&::-webkit-details-marker]:hidden">
                        <x-ui.icon name="block" :size="18" />Reject
                        <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
                    </summary>
                    <form method="POST" action="{{ route('owner.orders.reject', $order) }}" class="space-y-3 px-4 pb-4">
                        @csrf
                        <input type="hidden" name="_form" value="reject">
                        <x-ui.textarea id="reject-reason" name="reason" label="Reason" maxlength="1000" rows="3" :required="true" hint="The customer sees this reason.">{{ $openForm === 'reject' ? old('reason') : '' }}</x-ui.textarea>
                        <x-ui.button type="submit" variant="danger">Reject order</x-ui.button>
                    </form>
                </details>
            @endif

            @if (in_array(Status::Cancelled->value, $moveValues, true))
                <details class="group rounded-card border border-app-border open:bg-neutral-50 dark:open:bg-white/5" @if ($openForm === 'cancel') open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 text-sm font-semibold text-app-text-2 focus-ring [&::-webkit-details-marker]:hidden">
                        <x-ui.icon name="cancel" :size="18" />Cancel order
                        <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
                    </summary>
                    <form method="POST" action="{{ route('owner.orders.cancel', $order) }}" class="space-y-3 px-4 pb-4">
                        @csrf
                        <input type="hidden" name="_form" value="cancel">
                        <x-ui.textarea id="cancel-note" name="note" label="Note (optional)" maxlength="500" rows="2" hint="Shown in the order's history, which the customer can read.">{{ $openForm === 'cancel' ? old('note') : '' }}</x-ui.textarea>
                        <x-ui.button type="submit" variant="danger">Cancel order {{ $order->reference_number }}</x-ui.button>
                    </form>
                </details>
            @endif
        </div>
    </section>

    <x-ui.notice variant="payment" class="mt-6">
        <p class="text-app-text-2">The system records payments received at the shop (cash or GCash); it never takes payment online.</p>
    </x-ui.notice>

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-12">
        <div class="min-w-0 space-y-6 xl:col-span-7">
            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="customer-title">
                <h2 id="customer-title" class="sr-only">Customer</h2>
                <div class="flex items-center gap-4">
                    <span class="grid size-12 shrink-0 place-items-center rounded-full bg-neutral-100 font-display text-lg font-semibold text-app-text-2 dark:bg-white/10" aria-hidden="true">{{ Str::upper(Str::substr($customer?->name ?? '?', 0, 2)) }}</span>
                    <div class="min-w-0">
                        <p class="break-words text-lg font-semibold">{{ $customer?->name ?? 'Deleted account' }}</p>
                        <p class="text-xs text-app-text-2">Customer</p>
                    </div>
                </div>
                @if ($customer)
                    <dl class="mt-4 grid gap-2 rounded-card bg-neutral-100 p-3 text-sm sm:grid-cols-2 dark:bg-white/5">
                        <div class="flex min-w-0 items-center gap-2">
                            <dt><x-ui.icon name="call" :size="18" class="text-app-text-2" /><span class="sr-only">Phone</span></dt>
                            <dd class="min-w-0 truncate">{{ $customer->phone ?: 'No phone number' }}</dd>
                        </div>
                        <div class="flex min-w-0 items-center gap-2">
                            <dt><x-ui.icon name="mail" :size="18" class="text-app-text-2" /><span class="sr-only">Email</span></dt>
                            <dd class="min-w-0 truncate">{{ $customer->email }}</dd>
                        </div>
                    </dl>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($customer->phone)
                            <x-ui.button :href="'tel:'.preg_replace('/[^0-9+]/', '', $customer->phone)" variant="outline" size="sm"><x-ui.icon name="call" :size="16" />Call</x-ui.button>
                        @endif
                        <x-ui.button :href="'mailto:'.$customer->email" variant="outline" size="sm"><x-ui.icon name="mail" :size="16" />Email</x-ui.button>
                    </div>
                @endif
            </section>

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="files-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="files-title" class="font-display text-xl font-semibold">Files</h2>
                    <span class="font-mono text-xs text-app-text-2">{{ $order->files->count() }} {{ Str::plural('file', $order->files->count()) }}</span>
                </div>
                <x-order.files :files="$order->files" :customer-id="$order->customer_id" viewer="owner" class="mt-4" />
            </section>

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="specs-title">
                <h2 id="specs-title" class="font-display text-xl font-semibold">Specifications</h2>
                <x-order.specs :order="$order" viewer="owner" class="mt-4" />
            </section>
        </div>

        <div class="min-w-0 space-y-6 xl:col-span-5">
            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-md sm:p-6 dark:shadow-none" aria-labelledby="price-title">
                <h2 id="price-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="payments" :size="22" class="text-brand" />Price</h2>
                <dl class="mt-4 space-y-2 text-sm">
                    @if (($snapshot['service']['base_price'] ?? null) !== null)
                        <div class="flex justify-between gap-3">
                            <dt class="text-app-text-2">Base price ({{ \App\Enums\PricingType::tryFrom($snapshot['service']['pricing_type'] ?? '')?->label() ?? 'service' }})</dt>
                            <dd class="font-mono text-xs font-semibold tabular-nums">{{ \App\Support\Money::format($snapshot['service']['base_price']) }}</dd>
                        </div>
                    @endif
                    @foreach ($order->chosenOptions() as $option)
                        <div class="flex justify-between gap-3">
                            <dt class="min-w-0 break-words text-app-text-2">{{ $option['group'] }}: {{ $option['label'] }}</dt>
                            <dd class="shrink-0 font-mono text-xs tabular-nums">{{ $option['price'] }}</dd>
                        </div>
                    @endforeach
                    <div class="flex justify-between gap-3 border-t border-app-border pt-2">
                        <dt class="text-app-text-2">Estimate when ordered</dt>
                        <dd class="font-semibold tabular-nums">{{ $order->estimateText() }}</dd>
                    </div>
                </dl>
                <div class="mt-4 flex items-baseline justify-between gap-3 rounded-card bg-neutral-100 p-4 dark:bg-white/5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Confirmed price</p>
                    <p class="text-right font-bold tabular-nums {{ $order->confirmed_price !== null ? 'text-2xl text-brand' : 'text-app-text-2' }}">{{ $order->confirmedText() ?? 'Not confirmed yet' }}</p>
                </div>

                @if ($canChangePrice)
                    <details class="group mt-3" @if ($openForm === 'price') open @endif>
                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-center gap-2 rounded-control bg-neutral-100 px-4 text-sm font-semibold transition-colors hover:bg-neutral-200 focus-ring dark:bg-white/10 dark:hover:bg-white/15 [&::-webkit-details-marker]:hidden">
                            <x-ui.icon name="edit" :size="18" />Change the confirmed price
                        </summary>
                        <form method="POST" action="{{ route('owner.orders.price', $order) }}" class="mt-3 space-y-3">
                            @csrf
                            <input type="hidden" name="_form" value="price">
                            <x-ui.input id="price-new" name="confirmed_price" label="New confirmed price (₱)" inputmode="decimal" :required="true" value="{{ $openForm === 'price' ? old('confirmed_price') : $order->confirmed_price }}" />
                            <x-ui.input id="price-reason" name="reason" label="Reason (optional)" maxlength="500" value="{{ $openForm === 'price' ? old('reason') : '' }}" hint="Saved in the history, which the customer can read." />
                            <x-ui.button type="submit">Save price</x-ui.button>
                        </form>
                    </details>
                @endif
            </section>

            <x-payment.card :payable="$order" viewer="owner" />

            @if ($order->needsInfo())
                <section class="rounded-card border border-status-amber/30 bg-status-amber-bg p-4" aria-labelledby="asked-title">
                    <h2 id="asked-title" class="flex items-center gap-2 text-sm font-semibold text-status-amber"><x-ui.icon name="help" :size="18" />You asked the customer</h2>
                    <p class="mt-2 whitespace-pre-line break-words text-sm">{{ $order->info_request }}</p>
                </section>
            @endif

            @if ($order->rejection_reason)
                <section class="rounded-card border border-status-red/30 bg-status-red-bg/50 p-4" aria-labelledby="reason-title">
                    <h2 id="reason-title" class="flex items-center gap-2 text-sm font-semibold text-status-red"><x-ui.icon name="block" :size="18" />Reason for rejecting</h2>
                    <p class="mt-2 whitespace-pre-line break-words text-sm">{{ $order->rejection_reason }}</p>
                </section>
            @endif

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="history-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="history-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="history" :size="22" class="text-brand" />History</h2>
                    <span class="font-mono text-xs text-app-text-2">Newest first</span>
                </div>
                <x-order.timeline :histories="$order->statusHistories" :customer-id="$order->customer_id" viewer="owner" newest-first class="mt-5" />
            </section>
        </div>
    </div>
@endsection
