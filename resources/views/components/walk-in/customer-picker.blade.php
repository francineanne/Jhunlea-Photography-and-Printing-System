@props(['picked' => null])

{{--
    Who the walk-in order or booking is for (Phase 8A): search existing customers by name or phone and pick one (two
    people may share a name, so the phone is shown), or add a new walk-in customer with a name and an optional phone.
    Without JavaScript only the "new walk-in customer" fields are used.
--}}
@php
    $pickedData = $picked ? ['id' => $picked->id, 'name' => $picked->name, 'phone' => $picked->phone, 'walk_in' => $picked->isWalkIn()] : null;
    $startNew = ! $picked && (filled(old('new_customer_name')) || $errors->hasAny(['new_customer_name', 'new_customer_phone']));
@endphp

<fieldset x-data="walkInCustomer({ url: @js(route('owner.walk-in.customers')), picked: @js($pickedData), mode: @js($startNew ? 'new' : 'search') })"
    class="rounded-card border border-app-border bg-app-card p-4 sm:p-5 dark:shadow-none">
    <legend class="sr-only">Customer</legend>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="flex items-center gap-2 font-display text-lg font-semibold"><x-ui.icon name="person" :size="20" class="text-brand" />Customer</h2>
        <div class="hidden gap-1 rounded-control bg-neutral-100 p-1 text-sm dark:bg-white/10 [.js_&]:flex" role="group" aria-label="Find or add the customer">
            <button type="button" x-on:click="mode = 'search'" x-bind:aria-pressed="mode === 'search'" x-bind:class="mode === 'search' ? 'bg-app-card shadow-sm text-app-text' : 'text-app-text-2'"
                class="min-h-11 rounded-lg px-3 font-semibold transition-colors focus-ring sm:min-h-9">Find a customer</button>
            <button type="button" x-on:click="mode = 'new'; picked = null" x-bind:aria-pressed="mode === 'new'" x-bind:class="mode === 'new' ? 'bg-app-card shadow-sm text-app-text' : 'text-app-text-2'"
                class="min-h-11 rounded-lg px-3 font-semibold transition-colors focus-ring sm:min-h-9">New walk-in</button>
        </div>
    </div>

    @error('customer')<p class="mt-3 text-sm font-semibold text-status-red" role="alert">{{ $message }}</p>@enderror
    @error('customer_id')<p class="mt-3 text-sm font-semibold text-status-red" role="alert">{{ $message }}</p>@enderror

    {{-- Find an existing customer (JavaScript) --}}
    <div x-show="mode === 'search'" x-cloak class="mt-4">
        <input type="hidden" name="customer_id" x-bind:value="mode === 'search' && picked ? picked.id : ''">

        <template x-if="picked">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-brand/40 bg-brand/5 p-3">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-semibold"><span class="break-words" x-text="picked.name"></span>
                        <span x-show="picked.walk_in" class="rounded-full bg-status-blue-bg px-2 py-0.5 text-[11px] font-bold text-status-blue">Walk-in</span></p>
                    <p class="font-mono text-xs text-app-text-2" x-text="picked.phone || 'No phone'"></p>
                </div>
                <x-ui.button type="button" variant="ghost" size="sm" class="min-h-11" x-on:click="clear()">Change</x-ui.button>
            </div>
        </template>

        <div x-show="! picked" class="relative">
            <x-ui.input label="Search by name or phone" type="search" icon="search" autocomplete="off" maxlength="60"
                x-ref="search" x-model="query" x-on:input="search()" hint="Type at least 2 letters or digits." />
            <ul x-show="results.length" class="mt-2 divide-y divide-app-border overflow-hidden rounded-control border border-app-border" role="listbox" aria-label="Matching customers">
                <template x-for="customer in results" x-bind:key="customer.id">
                    <li>
                        <button type="button" role="option" x-on:click="pick(customer)"
                            class="flex min-h-11 w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition-colors hover:bg-neutral-100 focus-ring dark:hover:bg-white/10">
                            <span class="min-w-0"><span class="block break-words font-semibold" x-text="customer.name"></span>
                                <span class="block font-mono text-xs text-app-text-2" x-text="customer.phone || 'No phone'"></span></span>
                            <span x-show="customer.walk_in" class="shrink-0 rounded-full bg-status-blue-bg px-2 py-0.5 text-[11px] font-bold text-status-blue">Walk-in</span>
                        </button>
                    </li>
                </template>
            </ul>
            <p x-show="query.trim().length >= 2 && ! searching && ! results.length" class="mt-2 text-sm text-app-text-2">
                No customer found. <button type="button" x-on:click="mode = 'new'" class="min-h-11 rounded font-semibold text-brand hover:underline focus-ring">Add a new walk-in</button>
            </p>
        </div>
    </div>

    {{-- A new walk-in customer (also the only choice without JavaScript) --}}
    <div x-show="mode === 'new'" class="mt-4 grid gap-4 sm:grid-cols-2">
        <x-ui.input label="Full name" name="new_customer_name" maxlength="80" :value="old('new_customer_name')" x-bind:disabled="mode !== 'new'"
            hint="A walk-in customer has no login. Search first so the same person is not added twice." />
        <x-ui.input label="Phone (optional)" name="new_customer_phone" type="tel" :value="old('new_customer_phone')" x-bind:disabled="mode !== 'new'"
            hint="For example 0917 123 4567." />
    </div>
</fieldset>
