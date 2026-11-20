@props(['picked' => null, 'forBooking' => false])

{{--
    Who the walk-in order or booking is for (Phase 8A): search existing customers by name or phone and pick one (two
    people may share a name, so the phone is shown), or add a new walk-in customer with a name and a phone.
    Without JavaScript only the "new walk-in customer" fields are used.

    Phase 10J, ONE phone: for a booking ($forBooking) the new customer's "Mobile number" is required and is also the
    booking's contact number; for a picked customer their saved number is used, with "Use a different number for this
    booking" to type another one (or a field to type one when they have none). For a printing order it is optional.
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
                    <p class="tabular-nums text-xs text-app-text-2" x-text="picked.phone || 'No phone'"></p>
                </div>
                <x-ui.button type="button" variant="ghost" size="sm" class="min-h-11" x-on:click="clear()">Change</x-ui.button>
            </div>
        </template>

        @if ($forBooking)
            {{-- The booking's contact number: the picked customer's own, or a different one typed here --}}
            <div x-show="picked" x-cloak class="mt-3" x-data="{ other: @js((bool) old('other_number')) }" data-contact-choice>
                <input type="hidden" name="other_number" x-bind:value="other && picked && picked.phone ? '1' : '0'">
                <p x-show="picked && picked.phone && ! other" class="text-sm text-app-text-2">
                    The studio calls <span class="tabular-nums font-semibold text-app-text" x-text="picked ? picked.phone : ''"></span> about this booking.
                    <button type="button" x-on:click="other = true; $nextTick(() => $refs.other.querySelector('input').focus())" class="ml-1 inline-flex min-h-11 items-center rounded font-semibold text-brand hover:underline focus-ring sm:min-h-0">Use a different number for this booking</button>
                </p>
                <div x-show="picked && (! picked.phone || other)" x-ref="other" class="max-w-sm">
                    <x-ui.input label="Mobile number for this booking" name="contact_number" type="tel" :value="old('contact_number')" :error="$errors->first('contact_number')"
                        x-bind:disabled="! (mode === 'search' && picked && (! picked.phone || other))"
                        hint="The studio calls this number about the booking and the downpayment deadline." />
                </div>
            </div>
        @endif

        <div x-show="! picked" class="relative">
            <x-ui.input label="Search by name or phone" type="search" icon="search" autocomplete="off" maxlength="60"
                x-ref="search" x-model="query" x-on:input="search()" hint="Type at least 2 letters or digits." />
            <ul x-show="results.length" class="mt-2 divide-y divide-app-border overflow-hidden rounded-control border border-app-border" role="listbox" aria-label="Matching customers">
                <template x-for="customer in results" x-bind:key="customer.id">
                    <li>
                        <button type="button" role="option" x-on:click="pick(customer)"
                            class="flex min-h-11 w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition-colors hover:bg-neutral-100 focus-ring dark:hover:bg-white/10">
                            <span class="min-w-0"><span class="block break-words font-semibold" x-text="customer.name"></span>
                                <span class="block tabular-nums text-xs text-app-text-2" x-text="customer.phone || 'No phone'"></span></span>
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
    <div x-show="mode === 'new'" class="mt-4 form-grid sm:grid-cols-2">
        <x-ui.input label="Full name" name="new_customer_name" maxlength="80" :value="old('new_customer_name')" x-bind:disabled="mode !== 'new'"
            x-on:input="checkNew($event.target.value)"
            hint="A walk-in customer has no login. Search first so the same person is not added twice." />
        <x-ui.input :label="$forBooking ? 'Mobile number' : 'Mobile number (optional)'" name="new_customer_phone" type="tel" :value="old('new_customer_phone')" x-bind:disabled="mode !== 'new'"
            :required="$forBooking" :error="$errors->first('new_customer_phone')"
            x-on:input="checkNew($event.target.value)"
            :hint="$forBooking ? 'For example 0917 123 4567. Saved on the customer and used to call about this booking.' : 'For example 0917 123 4567.'" />
        {{-- Phase 10B: an existing customer with the same name or phone is offered before a second one is created --}}
        <div x-show="matches.length" x-cloak class="rounded-control border border-status-amber/40 bg-status-amber-bg/60 p-3 sm:col-span-2" role="status">
            <p class="text-sm font-semibold">Possible match</p>
            <ul class="mt-1 space-y-1">
                <template x-for="customer in matches" x-bind:key="customer.id">
                    <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span><span class="font-semibold" x-text="customer.name"></span> <span class="text-app-text-2" x-text="customer.phone ? '· ' + customer.phone : ''"></span></span>
                        <button type="button" x-on:click="useMatch(customer)" class="inline-flex min-h-11 items-center rounded px-2 font-semibold text-brand hover:underline focus-ring">Use this customer</button>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</fieldset>
