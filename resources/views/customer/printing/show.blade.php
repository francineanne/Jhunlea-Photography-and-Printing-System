@extends('layouts.customer')

@section('title', 'Order '.$order->reference_number.' | '.config('app.name'))

@php
    use App\Enums\PrintingOrderStatus;

    $fileErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'files'))->flatten()->unique();
@endphp

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('customer.dashboard') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">My dashboard</a></li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ route('customer.printing.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">My printing orders</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">{{ $order->reference_number }}</li>
        </ol>
    </nav>

    {{-- Header: reference, status and the price that counts right now --}}
    <section class="flex flex-col gap-4 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 md:flex-row md:items-center md:justify-between dark:shadow-none">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded bg-neutral-100 px-2 py-1 font-mono text-xs font-semibold uppercase dark:bg-white/10">Order {{ $order->reference_number }}</span>
                <x-ui.status-badge :status="$order->status" live />
                @if ($order->needsInfo())
                    <x-ui.badge tone="red" pulse="ping">The shop needs information</x-ui.badge>
                @endif
            </div>
            <h1 class="mt-3 break-words font-display text-2xl font-semibold leading-tight sm:text-3xl">{{ $order->serviceName() }}</h1>
            <p class="mt-1 text-sm text-app-text-2">Sent on <x-ui.date :value="$order->created_at" /></p>
        </div>
        <div class="shrink-0 rounded-card bg-neutral-100 p-4 md:text-right dark:bg-white/5">
            <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">{{ $order->confirmed_price !== null ? 'Confirmed price' : 'Estimated price' }}</p>
            <p class="mt-0.5 font-bold tabular-nums text-brand {{ $order->confirmed_price === null && $order->estimated_price === null ? 'text-base' : 'text-2xl' }}">{{ $order->confirmedText() ?? $order->estimateText() }}</p>
        </div>
    </section>

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-12">
        <div class="min-w-0 space-y-6 lg:col-span-8">
            @if ($order->needsInfo())
                <section class="relative overflow-hidden rounded-card border border-status-red/30 bg-status-red-bg/50 p-4 sm:p-6" aria-labelledby="info-title" role="region">
                    <span class="absolute inset-y-0 left-0 w-1.5 bg-brand" aria-hidden="true"></span>
                    <div class="flex items-start gap-3 pl-2">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand text-white" aria-hidden="true"><x-ui.icon name="help" :size="22" /></span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-brand">Action needed</p>
                            <h2 id="info-title" class="font-semibold">The shop asked for more information</h2>
                            <blockquote class="mt-3 whitespace-pre-line break-words rounded-control bg-app-card p-3 text-sm italic">{{ $order->info_request }}</blockquote>
                            <p class="mt-3 text-sm text-app-text-2">Reply by adding the file the shop needs in <a href="#files" class="font-semibold text-brand underline-offset-2 hover:underline focus-ring rounded">Files</a>, or talk to the shop. Adding a file marks the request as answered.</p>
                        </div>
                    </div>
                </section>
            @endif

            @if ($order->status === PrintingOrderStatus::Rejected && $order->rejection_reason)
                <section class="rounded-card border border-status-red/30 bg-status-red-bg/50 p-4 sm:p-6" aria-labelledby="rejected-title">
                    <h2 id="rejected-title" class="flex items-center gap-2 font-semibold text-status-red"><x-ui.icon name="block" :size="20" />The shop could not accept this order</h2>
                    <p class="mt-2 whitespace-pre-line break-words text-sm">{{ $order->rejection_reason }}</p>
                </section>
            @endif

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="timeline-title">
                <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Progress</p>
                <h2 id="timeline-title" class="font-display text-xl font-semibold">Order timeline</h2>
                <x-order.timeline :histories="$order->statusHistories" :customer-id="$order->customer_id" class="mt-5" />
            </section>

            <section id="files" class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="files-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="files-title" class="font-display text-xl font-semibold">Files</h2>
                    <span class="font-mono text-xs text-app-text-2">{{ $order->files->count() }} / {{ $maxFiles }}</span>
                </div>
                <p class="mt-1 text-sm text-app-text-2">Only you and the shop can open these files.</p>

                <x-order.files :files="$order->files" :customer-id="$order->customer_id" class="mt-4" />

                @if ($canAddFiles)
                    <form method="POST" action="{{ route('customer.printing.files.store', $order) }}" enctype="multipart/form-data" class="mt-5 border-t border-app-border pt-5">
                        @csrf
                        <h3 class="font-semibold">Add files</h3>
                        <p class="mt-1 text-sm text-app-text-2">
                            @if ($room > 0)
                                You can add {{ $room }} more {{ Str::plural('file', $room) }}, up to {{ $maxFileMb }} MB each and {{ $maxTotalMb }} MB for all the files of this order.
                            @else
                                This order already has the most files it can hold.
                            @endif
                        </p>

                        @if ($fileErrors->isNotEmpty())
                            <ul class="mt-3 space-y-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert" id="add-files-error">
                                @foreach ($fileErrors as $message)
                                    <li class="flex items-start gap-1"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span class="min-w-0 break-words">{{ $message }}</span></li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($room > 0)
                            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center">
                                <label for="add-files" class="sr-only">Files to add</label>
                                <input id="add-files" type="file" name="files[]" multiple required
                                    accept="{{ $accept }}"
                                    @if ($fileErrors->isNotEmpty()) aria-invalid="true" aria-describedby="add-files-error" @endif
                                    class="block w-full min-w-0 cursor-pointer rounded-control text-sm text-app-text-2 file:mr-3 file:h-11 file:cursor-pointer file:rounded-control file:border-0 file:bg-neutral-200 file:px-4 file:text-sm file:font-semibold file:text-app-text file:transition-colors hover:file:bg-neutral-300 focus-ring dark:file:bg-white/10 dark:file:text-white">
                                <x-ui.button type="submit" class="shrink-0"><x-ui.icon name="upload" :size="18" />Add files</x-ui.button>
                            </div>
                        @endif
                    </form>
                @endif
            </section>

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="specs-title">
                <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Your choices</p>
                <h2 id="specs-title" class="font-display text-xl font-semibold">What you ordered</h2>
                <x-order.specs :order="$order" class="mt-4" />
            </section>

            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="cancel-title">
                <h2 id="cancel-title" class="flex items-center gap-2 font-display text-xl font-semibold">
                    <x-ui.icon :name="$canCancel ? 'lock_open' : 'lock'" :size="20" class="text-app-text-2" />Cancelling
                </h2>
                @if ($canCancel)
                    <p class="mt-1 text-sm text-app-text-2">You can cancel this order while it is Submitted, before the shop starts reviewing it.</p>
                    <details class="group mt-4">
                        <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-control border border-status-red/40 px-4 text-sm font-semibold text-status-red transition-colors hover:bg-status-red-bg focus-ring [&::-webkit-details-marker]:hidden">
                            <x-ui.icon name="cancel" :size="18" />Cancel this order
                        </summary>
                        <form method="POST" action="{{ route('customer.printing.cancel', $order) }}" class="mt-4 space-y-3 rounded-card bg-neutral-100 p-4 dark:bg-white/5">
                            @csrf
                            <x-ui.textarea id="cancel-reason" name="reason" label="Reason (optional)" maxlength="500" rows="3" hint="Up to 500 characters. The shop sees it.">{{ old('reason') }}</x-ui.textarea>
                            <div class="flex flex-wrap gap-2">
                                <x-ui.button type="submit" variant="danger">Yes, cancel order {{ $order->reference_number }}</x-ui.button>
                            </div>
                        </form>
                    </details>
                @else
                    <p class="mt-1 text-sm text-app-text-2">
                        @if ($order->status->isFinal())
                            This order is {{ strtolower($order->status->label()) }}.
                        @else
                            Orders can be cancelled here only while they are Submitted. To change this order now, please talk to the shop.
                        @endif
                    </p>
                @endif
            </section>
        </div>

        <aside class="min-w-0 space-y-6 lg:sticky lg:top-28 lg:col-span-4" aria-label="Price">
            <div class="rounded-card border border-app-border bg-app-card p-4 shadow-md sm:p-6 dark:shadow-none">
                <p class="text-[11px] font-bold uppercase tracking-wider text-brand">Price</p>
                <dl class="mt-3 space-y-3 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-app-text-2">Estimate when you ordered</dt>
                        <dd class="text-right font-semibold tabular-nums">{{ $order->estimateText() }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 border-t border-app-border pt-3">
                        <dt class="font-semibold">Confirmed price</dt>
                        <dd class="text-right font-bold tabular-nums {{ $order->confirmed_price !== null ? 'text-xl text-brand' : 'text-app-text-2' }}">{{ $order->confirmedText() ?? 'Not confirmed yet' }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs italic text-app-text-2">The estimate is not final. The shop confirms the price after checking your files.</p>
            </div>

            <x-ui.notice variant="payment">
                <p class="text-app-text-2">Nothing is charged online. The shop confirms the final price before you pay.</p>
            </x-ui.notice>
        </aside>
    </div>
@endsection
