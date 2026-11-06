@extends('layouts.owner')

@section('title', 'New walk-in order')

{{--
    A walk-in printing order at the counter (Phase 8A), in the style of design/admin/printing_order_detail_owner. The
    shop prints only after it is paid, so the order and its full payment are saved together: customer, service and
    options, the price (the catalog price or one the owner types), then the payment. Cash tendered only works out the
    change; it is never saved.
--}}
@php
    use App\Enums\PaymentMethod;

    $initial = [
        'service' => old('service', ''),
        'quantity' => old('quantity', 1),
        'pages' => old('pages', 1),
        'priceChoice' => old('price_choice', 'catalog'),
        'customPrice' => old('custom_price', ''),
        'method' => old('method', 'cash'),
        'tendered' => old('cash_tendered', ''),
    ];
@endphp

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('owner.orders.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Printing orders</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">New walk-in order</li>
        </ol>
    </nav>

    <div class="max-w-3xl">
        <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Counter</p>
        <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">New walk-in order</h1>
        <p class="mt-2 text-app-text-2">For a customer at the shop. The order is saved together with its full payment, so it is ready to print.</p>
    </div>

    @if ($errors->any())
        <x-ui.notice class="mt-6 max-w-3xl !border-status-red/30 !bg-status-red-bg" role="alert">
            <p class="font-semibold">Nothing was saved. Please check:</p>
            <ul class="mt-1 list-disc pl-5">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        </x-ui.notice>
    @endif

    <form method="POST" action="{{ route('owner.orders.walk-in.store') }}" enctype="multipart/form-data" class="mt-6 max-w-3xl space-y-6"
        x-data="walkInOrder({ priceUrl: @js(route('owner.orders.walk-in.price')), perPage: @js($perPage), initial: @js($initial) })">
        @csrf
        <input type="hidden" name="counter_token" value="{{ old('counter_token', $token) }}">

        <x-walk-in.customer-picker :picked="$picked" />

        <section class="rounded-card border border-app-border bg-app-card p-4 sm:p-5 dark:shadow-none" aria-labelledby="print-title">
            <h2 id="print-title" class="flex items-center gap-2 font-display text-lg font-semibold"><x-ui.icon name="print" :size="20" class="text-brand" />What to print</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.select label="Printing service" name="service" :required="true" x-model="service" :error="$errors->first('service')">
                        <option value="">Choose a service</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}" @selected((string) old('service') === (string) $service->id)>{{ $service->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @foreach ($services as $service)
                    @if ($service->options->isNotEmpty())
                        <div class="grid gap-4 sm:col-span-2 sm:grid-cols-2" x-show="service == {{ $service->id }}">
                            @foreach ($service->options->groupBy(fn ($option) => $option->group->value) as $group => $options)
                                <x-ui.select :label="\App\Enums\OptionGroup::from($group)->label()" name="options[{{ $service->id }}][{{ $group }}]" x-on:change="refresh()"
                                    :error="$errors->first('options.'.$group)">
                                    <option value="">Choose</option>
                                    @foreach ($options as $option)
                                        <option value="{{ $option->id }}" @selected((string) old('options.'.$service->id.'.'.$group) === (string) $option->id)>{{ $option->label }}</option>
                                    @endforeach
                                </x-ui.select>
                            @endforeach
                        </div>
                    @endif
                @endforeach

                <x-ui.input label="Copies" name="quantity" type="number" min="1" max="{{ config('printing.max_quantity') }}" :required="true" x-model.number="quantity" :value="old('quantity', 1)" :error="$errors->first('quantity')" />
                <div x-show="isPerPage()">
                    <x-ui.input label="Pages per copy" name="pages" type="number" min="1" max="{{ config('printing.max_pages') }}" x-model.number="pages" :value="old('pages', 1)" :error="$errors->first('pages')" />
                </div>
                <div class="sm:col-span-2">
                    <x-ui.textarea label="Note (optional)" name="notes" rows="2" maxlength="1000" :error="$errors->first('notes')">{{ old('notes') }}</x-ui.textarea>
                </div>
                <div class="sm:col-span-2">
                    <x-ui.input label="Files (optional)" name="files[]" type="file" multiple :error="$errors->first('files')"
                        hint="Only if the customer gave a file to keep with the order. The usual file types and size limits apply." />
                </div>
            </div>
        </section>

        <section class="rounded-card border border-app-border bg-app-card p-4 sm:p-5 dark:shadow-none" aria-labelledby="price-title">
            <h2 id="price-title" class="flex items-center gap-2 font-display text-lg font-semibold"><x-ui.icon name="sell" :size="20" class="text-brand" />Price</h2>
            <div class="mt-4 space-y-3" role="radiogroup" aria-labelledby="price-title">
                <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-control border border-app-border p-3 has-[:checked]:border-brand has-[:checked]:bg-brand/5">
                    <input type="radio" name="price_choice" value="catalog" x-model="priceChoice" class="mt-1 accent-[#C62828]" @checked(old('price_choice', 'catalog') === 'catalog')>
                    <span class="min-w-0">
                        <span class="block font-semibold">Catalog price</span>
                        <span class="block text-sm text-app-text-2" aria-live="polite"><span x-show="loading">Working it out…</span><span x-show="! loading" x-text="catalogText || 'Choose the service and options.'">From the service’s own prices.</span></span>
                    </span>
                </label>
                <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-control border border-app-border p-3 has-[:checked]:border-brand has-[:checked]:bg-brand/5">
                    <input type="radio" name="price_choice" value="custom" x-model="priceChoice" class="mt-1 accent-[#C62828]" @checked(old('price_choice') === 'custom')>
                    <span class="block font-semibold">Type a price</span>
                </label>
                <div x-show="priceChoice === 'custom'" class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Price (₱)" name="custom_price" inputmode="decimal" x-model="customPrice" :value="old('custom_price')" :error="$errors->first('custom_price')" hint="For example 150 or 150.50." />
                    <x-ui.input label="Why it differs (optional)" name="price_note" maxlength="200" :value="old('price_note')" :error="$errors->first('price_note')" hint="Kept in the order history with your name." />
                </div>
                @error('price_choice')<p class="text-sm font-semibold text-status-red">{{ $message }}</p>@enderror
            </div>
        </section>

        <section class="rounded-card border border-app-border bg-app-card p-4 sm:p-5 dark:shadow-none" aria-labelledby="pay-title">
            <h2 id="pay-title" class="flex items-center gap-2 font-display text-lg font-semibold"><x-ui.icon name="payments" :size="20" class="text-brand" />Payment</h2>
            <p class="mt-1 text-sm text-app-text-2">The full price, paid now, face to face. No partial payment.</p>
            <div class="mt-4 flex flex-wrap gap-2" role="radiogroup" aria-label="Payment method">
                @foreach ($methods as $method)
                    <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-app-border px-4 font-semibold has-[:checked]:border-brand has-[:checked]:bg-brand/5">
                        <input type="radio" name="method" value="{{ $method->value }}" x-model="method" class="accent-[#C62828]" @checked(old('method', 'cash') === $method->value)>
                        <x-ui.icon :name="$method->icon()" :size="18" />{{ $method->label() }}
                    </label>
                @endforeach
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div x-show="method === 'gcash'">
                    <x-ui.input label="GCash reference" name="gcash_reference" maxlength="40" autocomplete="off" :value="old('gcash_reference')" :error="$errors->first('gcash_reference')" hint="From the customer’s GCash receipt." />
                </div>
                <div x-show="method === 'cash'">
                    <x-ui.input label="Cash received (optional)" name="cash_tendered" inputmode="decimal" x-model="tendered" :value="old('cash_tendered')" :error="$errors->first('cash_tendered')" hint="Only to work out the change. Not saved." />
                </div>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 rounded-control bg-neutral-100 p-3 text-sm dark:bg-white/5">
                <div><dt class="text-xs text-app-text-2">Amount to collect</dt><dd class="text-xl font-bold tabular-nums" x-text="dueText()" aria-live="polite">—</dd></div>
                <div x-show="method === 'cash'"><dt class="text-xs text-app-text-2">Change due</dt><dd class="text-xl font-bold tabular-nums" x-text="changeText()" aria-live="polite">—</dd></div>
            </dl>
            @error('payment')<p class="mt-3 text-sm font-semibold text-status-red" role="alert">{{ $message }}</p>@enderror
            <div class="mt-4">
                <x-ui.checkbox name="collected" value="1" :error="$errors->first('collected')">I collected the full amount from the customer.</x-ui.checkbox>
            </div>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
            <x-ui.button :href="route('owner.orders.index')" variant="outline">Cancel</x-ui.button>
            <x-ui.button type="submit"><x-ui.icon name="check_circle" :size="18" />Save order and payment</x-ui.button>
        </div>
    </form>
@endsection
