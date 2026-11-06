@extends('layouts.owner')

@section('title', 'New walk-in booking')

{{--
    A walk-in photography booking at the counter (Phase 8A), in the style of design/admin/booking_detail_owner. It is
    saved confirmed. The free times come from the same schedule as the online calendar, without the minimum notice and
    the advance limit (the customer is here); a time that clashes with another session is never allowed. The price is
    optional: the booking is paid on the day of the shoot with the usual "Record payment".
--}}
@php
    $initial = ['package' => old('package', ''), 'date' => old('date', $today), 'time' => old('time', '')];
@endphp

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('owner.bookings.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Photography bookings</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">New walk-in booking</li>
        </ol>
    </nav>

    <div class="max-w-3xl">
        <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Counter</p>
        <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">New walk-in booking</h1>
        <p class="mt-2 text-app-text-2">For a customer at the shop. It is saved as confirmed and blocks the time for online customers.</p>
    </div>

    @if ($errors->any())
        <x-ui.notice class="mt-6 max-w-3xl !border-status-red/30 !bg-status-red-bg" role="alert">
            <p class="font-semibold">Nothing was saved. Please check:</p>
            <ul class="mt-1 list-disc pl-5">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        </x-ui.notice>
    @endif

    <form method="POST" action="{{ route('owner.bookings.walk-in.store') }}" class="mt-6 max-w-3xl space-y-6"
        x-data="walkInBooking({ slotsUrl: @js(route('owner.bookings.walk-in.slots')), initial: @js($initial) })">
        @csrf
        <input type="hidden" name="counter_token" value="{{ old('counter_token', $token) }}">

        <x-walk-in.customer-picker :picked="$picked" />

        <section class="rounded-card border border-app-border bg-app-card p-4 sm:p-5 dark:shadow-none" aria-labelledby="session-title">
            <h2 id="session-title" class="flex items-center gap-2 font-display text-lg font-semibold"><x-ui.icon name="photo_camera" :size="20" class="text-brand" />Session</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.select label="Package" name="package" :required="true" x-model="package" :error="$errors->first('package')">
                        <option value="">Choose a package</option>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}" @selected((string) old('package') === (string) $package->id)>{{ $package->name }} · {{ $package->duration_minutes }} min · up to {{ $package->max_people }} {{ Str::plural('person', $package->max_people) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @foreach ($packages as $package)
                    @if ($package->themes->isNotEmpty())
                        <div class="sm:col-span-2" x-show="package == {{ $package->id }}">
                            <x-ui.select label="Backdrop (optional)" name="theme" x-bind:disabled="package != {{ $package->id }}" :error="$errors->first('theme')">
                                <option value="">No backdrop chosen</option>
                                @foreach ($package->themes as $theme)
                                    <option value="{{ $theme->id }}" @selected((string) old('theme') === (string) $theme->id)>{{ $theme->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    @endif
                @endforeach

                <div class="sm:col-span-2">
                    <x-ui.input label="Their own idea (optional)" name="custom_theme_text" maxlength="500" :value="old('custom_theme_text')" :error="$errors->first('custom_theme_text')" />
                </div>
                <x-ui.input label="Date" name="date" type="date" :required="true" x-model="date" :value="old('date', $today)" min="{{ $today }}" :error="$errors->first('date')" />
                <div>
                    <x-ui.select label="Start time" name="time" :required="true" x-model="time" :error="$errors->first('time') ?: $errors->first('starts_at')">
                        <option value="">Choose a time</option>
                        <template x-for="slot in times" x-bind:key="slot.value">
                            <option x-bind:value="slot.value" x-text="slot.label" x-bind:selected="slot.value === time"></option>
                        </template>
                    </x-ui.select>
                    <p class="mt-1 text-xs text-app-text-2" aria-live="polite">
                        <span x-show="loading">Looking for free times…</span>
                        <span x-show="! loading && checked && ! times.length">No free time on this day (closed, blocked or fully booked).</span>
                        <span x-show="! loading && times.length" x-text="times.length + ' free ' + (times.length === 1 ? 'time' : 'times')"></span>
                    </p>
                    <noscript><x-ui.input label="Start time (HH:MM, 24-hour)" name="time" :value="old('time')" /></noscript>
                </div>
                <x-ui.input label="How many people" name="group_size" type="number" min="1" :required="true" :value="old('group_size', 1)" :error="$errors->first('group_size')" />
                <x-ui.input label="Contact number" name="contact_number" type="tel" :required="true" :value="old('contact_number')" :error="$errors->first('contact_number')" hint="The number the studio can call about this session." />
                <div class="sm:col-span-2">
                    <x-ui.textarea label="Note (optional)" name="notes" rows="2" maxlength="2000" :error="$errors->first('notes')">{{ old('notes') }}</x-ui.textarea>
                </div>
                <x-ui.input label="Confirmed price (optional)" name="confirmed_price" inputmode="decimal" :value="old('confirmed_price')" :error="$errors->first('confirmed_price')"
                    hint="Leave empty to set it later. Paid on the day of the shoot." />
            </div>
            <div class="mt-4">
                <x-ui.checkbox name="outside_schedule" value="1" :checked="(bool) old('outside_schedule')">Confirm anyway if it is outside the opening hours or on a blocked day</x-ui.checkbox>
                <p class="mt-1 text-xs text-app-text-2">A time that clashes with another session, or has already passed, is never allowed.</p>
            </div>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
            <x-ui.button :href="route('owner.bookings.index')" variant="outline">Cancel</x-ui.button>
            <x-ui.button type="submit"><x-ui.icon name="event_available" :size="18" />Save confirmed booking</x-ui.button>
        </div>
    </form>
@endsection
