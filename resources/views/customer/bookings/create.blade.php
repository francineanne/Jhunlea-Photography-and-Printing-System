@extends('layouts.customer')

@section('title', 'Book a photoshoot | '.config('app.name'))

@php
    $steps = [
        1 => ['label' => 'Package', 'icon' => 'photo_camera'],
        2 => ['label' => 'Your details', 'icon' => 'person'],
        3 => ['label' => 'Date and time', 'icon' => 'calendar_month'],
        4 => ['label' => 'Backdrop', 'icon' => 'palette'],
        5 => ['label' => 'Prints', 'icon' => 'photo_library'],
        6 => ['label' => 'Review and send', 'icon' => 'task_alt'],
    ];
    $hasOld = session()->hasOldInput();
    $chosenId = $chosen?->id;
    $oldTheme = (string) old('theme');
    $imageErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'images'))->flatten()->unique();
    $people = fn (int $n) => $n === 1 ? 'person' : 'people';
@endphp

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('customer.dashboard') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">My dashboard</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">Book a photoshoot</li>
        </ol>
    </nav>

    <header class="max-w-2xl">
        <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-brand">
            <span class="size-2 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Photoshoot request
        </p>
        <h1 class="mt-1 font-display text-3xl font-semibold leading-tight sm:text-4xl">Book a photoshoot</h1>
        <p class="mt-2 text-app-text-2">Choose a package, a free time and a backdrop, then send your request. The studio confirms the time after reviewing it.</p>
    </header>

    @if (session('error'))
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg"><p>{{ session('error') }}</p></x-ui.notice>
    @endif

    @if ($atLimit)
        <x-ui.notice class="mt-6 !border-status-amber/30 !bg-status-amber-bg" id="limit-notice">
            <p class="font-semibold">{{ $limitMessage }}</p>
            <p class="text-app-text-2">You can still look through the steps, but a new request can be sent only after the studio answers or you cancel one.</p>
        </x-ui.notice>
    @endif

    @if ($packages->isEmpty())
        <x-ui.card class="mt-8">
            <x-ui.empty-state icon="photo_camera" title="No photoshoot packages are open for booking yet"
                description="Please check back soon, or ask the studio what they can do for you.">
                <x-ui.button :href="route('customer.dashboard')" variant="outline">Back to my dashboard</x-ui.button>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <form method="POST" action="{{ route('customer.bookings.store') }}" enctype="multipart/form-data" novalidate
            x-data="bookingWizard(@js([
                'start' => $startStep,
                'packages' => $packageData,
                'slotsUrl' => route('customer.bookings.slots'),
                'bookableFrom' => $bookable[0],
                'bookableUntil' => $bookable[1],
                'oldSlot' => $oldSlot,
                'images' => $imageRules,
                'messages' => $messages,
                'atLimit' => $atLimit,
            ]))"
            x-on:change="sync()" x-on:submit="submit($event)">
            @csrf

            {{-- Stepper: six tiles and a track that fills as the steps advance (as in the design). Without scripts the tiles jump to the sections --}}
            <div class="mt-8 rounded-card border border-app-border bg-app-card p-2 shadow-[0_2px_8px_-2px_rgba(23,23,23,0.04),0_1px_4px_-1px_rgba(23,23,23,0.02)] dark:shadow-none sm:p-3">
                <ol class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($steps as $number => $item)
                        <li>
                            <a href="#step-{{ $number }}" x-on:click.prevent="goTo({{ $number }})"
                                x-bind:aria-current="step === {{ $number }} ? 'step' : false"
                                x-bind:aria-disabled="{{ $number }} > reached ? 'true' : 'false'"
                                class="flex min-h-14 items-center gap-2.5 rounded-control p-2 transition-colors duration-[280ms] ease-brand focus-ring"
                                x-bind:class="step === {{ $number }} ? 'bg-brand text-white shadow-sm' : ({{ $number }} > reached ? 'bg-neutral-100 opacity-70 dark:bg-white/5' : 'bg-neutral-200/70 hover:bg-neutral-200 dark:bg-white/10 dark:hover:bg-white/15')">
                                <span class="grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold transition-colors duration-[280ms] ease-brand"
                                    x-bind:class="step === {{ $number }} ? 'bg-white text-brand' : ({{ $number }} <= reached ? 'bg-brand text-white' : 'bg-neutral-200 text-app-text-2 dark:bg-white/10')">{{ $number }}</span>
                                <span class="min-w-0">
                                    <span class="block text-[10px] font-bold uppercase tracking-wider"
                                        x-bind:class="step === {{ $number }} ? 'text-white/80' : 'text-app-text-2'">Step {{ $number }}</span>
                                    <span class="block truncate text-sm font-semibold">{{ $item['label'] }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
                <div class="mx-1 mt-2 h-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/10" aria-hidden="true">
                    <div class="h-full rounded-full bg-brand transition-[width] duration-300 ease-brand" style="width: {{ round($startStep / 6 * 100, 2) }}%" x-bind:style="`width: ${(step / 6 * 100).toFixed(2)}%`"></div>
                </div>
            </div>

            <div class="mt-6 grid items-start gap-6 lg:grid-cols-12">
                <div class="min-w-0 space-y-6 lg:col-span-8">

                    {{-- Step 1: package --}}
                    <section id="step-1" aria-labelledby="step-1-title" x-show="step === 1"
                        @if ($startStep !== 1) data-js-hidden x-bind:data-js-hidden="false" @endif
                        class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-3 shadow-sm sm:p-6 dark:shadow-none">
                        @include('customer.printing._step-header', ['number' => 1, 'total' => 6, 'title' => 'Choose a package', 'text' => 'Pick the photoshoot you want. The price shown is an estimate until the studio confirms it.', 'pill' => $packages->count().' '.Str::plural('package', $packages->count())])

                        @error('package')
                            <p class="mb-4 flex items-start gap-1 text-sm text-status-red" id="package-error"><x-ui.icon name="error" :size="16" class="mt-0.5" /><span>{{ $message }}</span></p>
                        @enderror

                        <fieldset>
                            <legend class="sr-only">Photoshoot package</legend>
                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($packages as $package)
                                    <div class="flex flex-col">
                                        <label class="group relative flex flex-1 cursor-pointer flex-col rounded-card border border-app-border bg-neutral-50 p-4 transition-[background-color,border-color,box-shadow] duration-300 hover:bg-neutral-100 has-checked:border-brand has-checked:bg-app-card has-checked:shadow-md has-checked:ring-2 has-checked:ring-brand has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand dark:bg-white/5 dark:hover:bg-white/10 dark:has-checked:bg-white/10">
                                            <input type="radio" name="package" value="{{ $package->id }}" class="peer sr-only"
                                                @checked($chosenId === $package->id) @error('package') aria-describedby="package-error" @enderror>
                                            <span class="absolute -top-2.5 right-3 rounded-full bg-brand px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white opacity-0 shadow-sm transition-opacity group-has-checked:opacity-100" aria-hidden="true">Selected</span>
                                            <span class="flex items-start justify-between gap-2">
                                                @if ($package->coverImage)
                                                    <img src="{{ $package->coverImage->url() }}" alt="" width="80" height="60" loading="lazy" decoding="async" class="aspect-[4/3] w-20 rounded-lg object-cover">
                                                @else
                                                    <span class="grid size-10 place-items-center rounded-lg bg-neutral-200/70 text-app-text-2 transition-colors group-has-checked:bg-brand/10 group-has-checked:text-brand dark:bg-white/10" aria-hidden="true"><x-ui.icon name="photo_camera" :size="22" /></span>
                                                @endif
                                                <span class="grid size-5 shrink-0 place-items-center rounded-full bg-neutral-200 text-white transition-colors group-has-checked:bg-brand dark:bg-white/15" aria-hidden="true">
                                                    <x-ui.icon name="check" :size="14" class="opacity-0 transition-opacity group-has-checked:opacity-100" />
                                                </span>
                                            </span>
                                            <span class="mt-3 flex flex-wrap items-center gap-2">
                                                <span class="break-words font-semibold">{{ $package->name }}</span>
                                                <span class="rounded bg-neutral-200/70 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-app-text-2 dark:bg-white/10">{{ $package->category }}</span>
                                            </span>
                                            <span class="mt-2 text-sm font-semibold tabular-nums text-brand">{{ $package->priceText() }}</span>
                                            <span class="mt-3 space-y-1.5 text-sm text-app-text-2">
                                                <span class="flex items-center gap-1.5"><x-ui.icon name="schedule" :size="16" class="text-brand" />{{ $package->duration_minutes }} mins</span>
                                                <span class="flex items-center gap-1.5"><x-ui.icon name="group" :size="16" class="text-brand" />Up to {{ $package->max_people }} {{ $people($package->max_people) }}</span>
                                                @if ($package->digital_outputs)
                                                    <span class="flex items-center gap-1.5"><x-ui.icon name="photo_library" :size="16" class="text-brand" />{{ $package->digital_outputs }} edited {{ $package->digital_outputs === 1 ? 'photo' : 'photos' }}</span>
                                                @endif
                                            </span>
                                        </label>
                                        {{-- Without scripts the calendar and backdrops cannot follow the choice, so the page reloads with it --}}
                                        <a data-no-js-only href="{{ route('customer.bookings.create', ['package' => $package->slug]) }}#step-1"
                                            class="mt-2 inline-flex min-h-11 items-center justify-center rounded-control border border-app-border px-3 text-sm font-semibold hover:bg-neutral-100 focus-ring dark:hover:bg-white/10">
                                            {{ $chosenId === $package->id ? 'Chosen: free times and backdrops shown below' : 'Use this package' }}
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>

                        <div class="mt-5 flex flex-col gap-2 rounded-card bg-neutral-100 p-4 text-sm dark:bg-white/5 sm:flex-row sm:items-center sm:justify-between">
                            <span class="flex items-center gap-2"><x-ui.icon name="help" :size="20" class="shrink-0 text-brand" />Not sure which to choose? The package finder can suggest one.</span>
                            <a href="{{ route('packages.finder') }}" class="inline-flex min-h-11 items-center gap-1 rounded text-[11px] font-bold uppercase tracking-wider text-brand hover:underline focus-ring">Open the finder<x-ui.icon name="arrow_forward" :size="16" /></a>
                        </div>

                        @include('customer.printing._step-nav', ['number' => 1])
                    </section>

                    {{-- Step 2: details --}}
                    <section id="step-2" aria-labelledby="step-2-title" x-show="step === 2"
                        @if ($startStep !== 2) data-js-hidden x-bind:data-js-hidden="false" @endif
                        class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-3 shadow-sm sm:p-6 dark:shadow-none">
                        @include('customer.printing._step-header', ['number' => 2, 'total' => 6, 'title' => 'Your details', 'text' => 'Tell the studio how many people will be in the photos and how to reach you.', 'pill' => 'About you'])

                        <div class="grid gap-5 md:grid-cols-2">
                            <x-ui.field id="group_size" label="Number of people" :error="$errors->first('group_size')" :required="true">
                                <div class="flex items-center gap-1 rounded-control border border-neutral-200 bg-app-card p-0.5 dark:border-neutral-700">
                                    <button type="button" x-cloak x-on:click="changePeople(-1)" aria-label="One person fewer" aria-controls="group_size"
                                        class="grid size-11 shrink-0 place-items-center rounded-[8px] bg-neutral-100 transition-colors hover:bg-neutral-200 focus-ring dark:bg-white/10 dark:hover:bg-white/15">
                                        <x-ui.icon name="remove" :size="18" />
                                    </button>
                                    <input id="group_size" type="number" name="group_size" value="{{ old('group_size', 1) }}" min="1" max="{{ $chosen?->max_people }}" x-bind:max="maxPeople()" step="1" inputmode="numeric" required
                                        x-ref="people"
                                        @if ($errors->has('group_size')) aria-invalid="true" aria-describedby="group_size-error" @else aria-describedby="group_size-hint" @endif
                                        class="h-11 min-w-0 flex-1 rounded-[8px] bg-transparent text-center text-lg font-semibold tabular-nums field-focus">
                                    <button type="button" x-cloak x-on:click="changePeople(1)" aria-label="One person more" aria-controls="group_size"
                                        class="grid size-11 shrink-0 place-items-center rounded-[8px] bg-neutral-100 transition-colors hover:bg-neutral-200 focus-ring dark:bg-white/10 dark:hover:bg-white/15">
                                        <x-ui.icon name="add" :size="18" />
                                    </button>
                                </div>
                                {{-- The rule of the chosen package; it follows the package when the choice changes --}}
                                <p id="group_size-hint" class="mt-1.5 text-sm text-app-text-2" aria-live="polite" x-text="groupRule()">{{ $chosen ? \App\Services\BookingService::groupSizeMessage($chosen) : 'Each package says how many people it fits.' }}</p>
                            </x-ui.field>

                            <x-ui.input id="contact_number" name="contact_number" type="tel" label="Contact number" icon="call" :required="true"
                                autocomplete="tel" inputmode="tel" maxlength="{{ \App\Services\BookingService::CONTACT_NUMBER_MAX }}"
                                value="{{ old('contact_number', auth()->user()->phone) }}"
                                hint="The studio calls or texts this number about your request. 7 to 20 digits." />

                            <div class="md:col-span-2">
                                <x-ui.textarea id="notes" name="notes" label="Notes (optional)" maxlength="{{ \App\Services\BookingService::NOTES_MAX }}" rows="4"
                                    x-on:input="collect()" placeholder="Outfits, colours, ideas, anything the studio should know"
                                    hint="Up to {{ number_format(\App\Services\BookingService::NOTES_MAX) }} characters.">{{ old('notes') }}</x-ui.textarea>
                                <p class="mt-1 text-right font-mono text-xs text-app-text-2" x-cloak aria-hidden="true"><span x-text="lengths.notes"></span> / {{ number_format(\App\Services\BookingService::NOTES_MAX) }}</p>
                            </div>
                        </div>

                        @include('customer.printing._step-nav', ['number' => 2])
                    </section>

                    {{-- Step 3: date and time. The free times come from the server (AvailabilityService); the browser only shows them --}}
                    <section id="step-3" aria-labelledby="step-3-title" x-show="step === 3"
                        @if ($startStep !== 3) data-js-hidden x-bind:data-js-hidden="false" @endif
                        class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-3 shadow-sm sm:p-6 dark:shadow-none">
                        @include('customer.printing._step-header', ['number' => 3, 'total' => 6, 'title' => 'Date and time', 'text' => 'Pick a free time. It stays a request until the studio confirms it.', 'pill' => 'Philippine time'])

                        <p class="mb-4 inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-app-text-2 dark:bg-white/10">
                            <span class="size-2 rounded-full bg-brand" aria-hidden="true"></span>Requested, not yet confirmed by the studio
                        </p>

                        @error('starts_at')
                            <p class="mb-4 flex items-start gap-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert" id="starts_at-error"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                        @enderror

                        <p class="text-sm text-app-text-2"><x-ui.icon name="info" :size="16" class="mr-1 inline-block align-[-3px] text-brand" />{{ $windowText }} Times are in Philippine time.</p>

                        {{-- With scripts: a month calendar, then the times of the chosen day --}}
                        <div class="mt-4" x-cloak>
                            <input type="hidden" name="starts_at" x-bind:value="selected" disabled x-bind:disabled="false">

                            <p class="rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5" x-show="!packageId">Choose a package in step 1 first.</p>

                            <div x-show="packageId">
                                {{-- On phones the calendar reaches into the card's padding, so every day stays a 44px tap target at 360px --}}
                                <div class="-mx-2 rounded-card bg-neutral-100 p-1 dark:bg-white/5 sm:mx-0 sm:p-4">
                                    <div class="mb-3 flex items-center justify-between gap-2 px-1 pt-1 sm:p-0">
                                        <h3 class="font-display text-xl font-semibold" x-text="monthLabel()" aria-live="polite"></h3>
                                        <div class="flex items-center gap-1">
                                            <button type="button" x-on:click="moveMonth(-1)" x-bind:disabled="!canMove(-1)" aria-label="Previous month"
                                                class="grid size-11 place-items-center rounded-lg bg-app-card text-app-text-2 transition-colors hover:text-app-text focus-ring disabled:pointer-events-none disabled:opacity-40 dark:bg-white/10">
                                                <x-ui.icon name="chevron_left" :size="20" />
                                            </button>
                                            <button type="button" x-on:click="moveMonth(1)" x-bind:disabled="!canMove(1)" aria-label="Next month"
                                                class="grid size-11 place-items-center rounded-lg bg-app-card text-app-text-2 transition-colors hover:text-app-text focus-ring disabled:pointer-events-none disabled:opacity-40 dark:bg-white/10">
                                                <x-ui.icon name="chevron_right" :size="20" />
                                            </button>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-7 pb-1 text-center text-[11px] font-bold uppercase text-app-text-2" aria-hidden="true">
                                        @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)
                                            <span>{{ $weekday }}</span>
                                        @endforeach
                                    </div>
                                    <div class="relative">
                                        <div class="grid grid-cols-7 transition-opacity duration-200 sm:gap-1.5" x-bind:class="slotState === 'loading' ? 'opacity-50' : ''">
                                            <template x-for="cell in cells()" x-bind:key="cell.key">
                                                <button type="button" x-on:click="pickDay(cell)" x-bind:disabled="cell.state !== 'free'"
                                                    x-bind:aria-label="cell.label" x-bind:aria-pressed="cell.date && selectedDate === cell.date ? 'true' : 'false'"
                                                    x-bind:tabindex="cell.state === 'blank' ? -1 : null" x-bind:aria-hidden="cell.state === 'blank' ? 'true' : null"
                                                    class="relative grid h-11 w-full place-items-center rounded-lg text-sm tabular-nums transition-[background-color,color,box-shadow] duration-200 focus-ring"
                                                    x-bind:class="dayClass(cell)">
                                                    <span x-text="cell.day"></span>
                                                    <span x-show="cell.state === 'free' && selectedDate !== cell.date" class="absolute bottom-1 left-1/2 size-1.5 -translate-x-1/2 rounded-full bg-status-green" aria-hidden="true"></span>
                                                </button>
                                            </template>
                                        </div>
                                        <p class="absolute inset-0 grid place-items-center" x-show="slotState === 'loading'" role="status">
                                            <span class="inline-flex items-center gap-2 rounded-full bg-app-card px-3 py-1.5 text-sm shadow-sm"><x-ui.aperture-spinner :size="18" />Finding free times…</span>
                                        </p>
                                    </div>
                                    <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 px-1 pb-1 text-xs text-app-text-2 sm:p-0">
                                        <span class="inline-flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-status-green" aria-hidden="true"></span>Has free times</span>
                                        <span class="inline-flex items-center gap-1.5"><span class="line-through">12</span>No free times</span>
                                    </p>
                                </div>

                                <div class="mt-4 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert" x-show="slotState === 'error'">
                                    <p>The free times could not be loaded. Check your connection and try again.</p>
                                    <x-ui.button variant="outline" size="sm" class="mt-2" x-on:click="loadMonth(true)">Try again</x-ui.button>
                                </div>

                                <div class="mt-4" x-show="slotState === 'ready' && !monthHasSlots()">
                                    <x-ui.empty-state icon="event_busy" title="No free times in this period"
                                        description="Try another month or talk to the studio." />
                                </div>

                                <div class="mt-5" x-show="selectedDate">
                                    <p class="text-sm font-semibold">Free times on <span x-text="selectedDateText()"></span></p>
                                    <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4" role="radiogroup" aria-label="Free times">
                                        <template x-for="slot in daySlots()" x-bind:key="slot.starts_at">
                                            <button type="button" role="radio" x-on:click="pickSlot(slot)" x-bind:aria-checked="(selected === slot.starts_at).toString()"
                                                class="flex min-h-14 flex-col items-center justify-center rounded-control px-2 py-2 text-center transition-colors duration-200 focus-ring"
                                                x-bind:class="selected === slot.starts_at ? 'bg-brand text-white shadow-sm' : 'bg-neutral-100 hover:bg-neutral-200 dark:bg-white/5 dark:hover:bg-white/10'">
                                                <span class="flex items-center gap-1.5 font-mono text-sm font-semibold">
                                                    <x-ui.icon name="check_circle" :size="16" x-show="selected === slot.starts_at" />
                                                    <x-ui.icon name="schedule" :size="16" class="text-app-text-2" x-show="selected !== slot.starts_at" />
                                                    <span x-text="slot.time"></span>
                                                </span>
                                                <span class="text-[11px] opacity-80" x-text="slot.time_text"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                <p class="mt-4 flex items-start gap-1.5 text-sm text-app-text-2"><x-ui.icon name="info" :size="16" class="mt-0.5 shrink-0 text-brand" />The studio checks every request before it confirms the time.</p>
                            </div>
                        </div>

                        {{-- Without scripts: the next days with free times, as plain choices. Never shown (and never sent) when scripts run --}}
                        <fieldset class="mt-4" data-no-js-only x-bind:disabled="true">
                            <legend class="mb-2 text-sm font-semibold">Free times</legend>
                            @if (! $chosen)
                                <p class="rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">Choose a package first: pick “Use this package” in step 1 to see its free times here.</p>
                            @elseif ($fallbackDays->isEmpty())
                                <p class="rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">No free times in this period. Try another month or talk to the studio.</p>
                            @else
                                <p class="mb-3 text-sm text-app-text-2">The next {{ $fallbackDays->count() }} {{ Str::plural('day', $fallbackDays->count()) }} with free times for {{ $chosen->name }}:</p>
                                <div class="space-y-4">
                                    @foreach ($fallbackDays as $daySlots)
                                        <fieldset>
                                            <legend class="mb-1.5 text-sm font-semibold">{{ $daySlots->first()->dateText() }}</legend>
                                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                                @foreach ($daySlots as $slot)
                                                    <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-app-border px-3 text-sm has-checked:border-brand has-checked:ring-2 has-checked:ring-brand">
                                                        <input type="radio" name="starts_at" value="{{ $slot->value() }}" class="size-4 shrink-0 accent-brand" @checked(old('starts_at') === $slot->value())>
                                                        <span class="font-mono">{{ $slot->timeText() }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endforeach
                                </div>
                            @endif
                        </fieldset>

                        @include('customer.printing._step-nav', ['number' => 3])
                    </section>

                    {{-- Step 4: backdrop theme and moodboard --}}
                    <section id="step-4" aria-labelledby="step-4-title" x-show="step === 4"
                        @if ($startStep !== 4) data-js-hidden x-bind:data-js-hidden="false" @endif
                        class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-3 shadow-sm sm:p-6 dark:shadow-none">
                        @include('customer.printing._step-header', ['number' => 4, 'total' => 6, 'title' => 'Backdrop and moodboard', 'text' => 'Choose a backdrop, describe your own idea, or both. Reference images are optional.', 'pill' => 'Your look'])

                        @error('theme')
                            <p class="mb-4 flex items-start gap-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert" id="theme-error"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                        @enderror

                        <p class="rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5" x-cloak x-show="!packageId">Choose a package in step 1 to see its backdrops.</p>
                        @unless ($chosen)
                            <p class="rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5" data-no-js-only>Choose a package in step 1 to see its backdrops. You can still describe your own idea below.</p>
                        @endunless

                        {{-- One set of backdrops per package; only the chosen package's set is shown and sent --}}
                        @foreach ($packages as $package)
                            <fieldset data-themes-for="{{ $package->id }}" @if ($chosenId !== $package->id) hidden @endif
                                x-bind:hidden="packageId !== {{ $package->id }}" x-bind:disabled="packageId !== {{ $package->id }}">
                                <legend class="mb-2 text-sm font-semibold">Backdrops for <span class="text-app-text">{{ $package->name }}</span></legend>
                                <div class="grid grid-cols-2 gap-3 xl:grid-cols-3">
                                    @foreach ($package->themes as $theme)
                                        <label class="group relative flex cursor-pointer flex-col overflow-hidden rounded-card border border-app-border bg-neutral-50 transition-[background-color,border-color,box-shadow] duration-300 hover:bg-neutral-100 has-checked:border-brand has-checked:bg-app-card has-checked:shadow-sm has-checked:ring-2 has-checked:ring-brand has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand dark:bg-white/5 dark:hover:bg-white/10">
                                            <input type="radio" name="theme" value="{{ $theme->id }}" class="sr-only" data-name="{{ $theme->name }}"
                                                @checked($chosenId === $package->id && $oldTheme === (string) $theme->id) @error('theme') aria-describedby="theme-error" @enderror>
                                            <span class="relative block aspect-[4/3] overflow-hidden bg-neutral-100 sm:aspect-[16/9] dark:bg-white/5">
                                                @if ($theme->imageUrl())
                                                    <img src="{{ $theme->imageUrl() }}" alt="" width="640" height="360" loading="lazy" decoding="async" class="size-full object-cover transition-transform duration-[280ms] ease-brand group-hover:scale-[1.03]">
                                                @else
                                                    <x-ui.image-placeholder icon="palette" class="size-full" />
                                                @endif
                                                <span class="absolute right-2 top-2 grid size-6 place-items-center rounded-full bg-brand text-white opacity-0 shadow transition-opacity group-has-checked:opacity-100" aria-hidden="true"><x-ui.icon name="check" :size="16" /></span>
                                            </span>
                                            <span class="block p-3">
                                                <span class="block break-words text-sm font-semibold">{{ $theme->name }}</span>
                                                @if ($theme->description)
                                                    <span class="mt-0.5 block text-xs text-app-text-2">{{ $theme->description }}</span>
                                                @endif
                                                @if (! empty($theme->color_swatches))
                                                    <span class="mt-2 flex gap-1.5" aria-hidden="true">
                                                        @foreach ($theme->color_swatches as $color)
                                                            <span class="size-5 rounded-full ring-1 ring-black/10 dark:ring-white/20" style="background-color: {{ $color }}"></span>
                                                        @endforeach
                                                    </span>
                                                @endif
                                                <span class="mt-2 block text-[10px] font-bold uppercase tracking-wider text-app-text-2 group-has-checked:text-brand"><span class="group-has-checked:hidden">Choose</span><span class="hidden group-has-checked:inline">Chosen</span></span>
                                            </span>
                                        </label>
                                    @endforeach

                                    {{-- "No listed backdrop": only the customer's own idea counts --}}
                                    <label class="group flex min-h-24 cursor-pointer flex-col justify-center rounded-card border-2 border-dashed border-app-border p-4 text-center transition-colors duration-300 hover:bg-neutral-100 has-checked:border-brand has-checked:bg-brand/5 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand dark:hover:bg-white/5">
                                        <input type="radio" name="theme" value="" class="sr-only" data-name=""
                                            @checked($chosenId === $package->id && $hasOld && $oldTheme === '')>
                                        <x-ui.icon name="edit_note" :size="26" class="mx-auto text-brand" />
                                        <span class="mt-1 text-sm font-semibold">{{ $package->themes->isEmpty() ? 'Describe your own idea' : 'No listed backdrop' }}</span>
                                        <span class="mt-0.5 text-xs text-app-text-2">{{ $package->themes->isEmpty() ? 'This package has no listed backdrops yet.' : 'I will describe my own idea below.' }}</span>
                                    </label>
                                </div>
                            </fieldset>
                        @endforeach

                        <div class="mt-6">
                            <x-ui.textarea id="custom_theme_text" name="custom_theme_text" label="Describe your own idea (optional if you chose a backdrop)"
                                maxlength="{{ \App\Services\BookingService::CUSTOM_THEME_MAX }}" rows="3" x-on:input="collect()"
                                placeholder="For example: a garden look with soft pastel colours"
                                hint="Up to {{ \App\Services\BookingService::CUSTOM_THEME_MAX }} characters.">{{ old('custom_theme_text') }}</x-ui.textarea>
                            <p class="mt-1 text-right font-mono text-xs text-app-text-2" x-cloak aria-hidden="true"><span x-text="lengths.idea"></span> / {{ \App\Services\BookingService::CUSTOM_THEME_MAX }}</p>
                        </div>

                        {{-- Moodboard: optional reference images --}}
                        <div class="mt-6">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="text-sm font-semibold">Reference images (optional)</h3>
                                <p class="font-mono text-xs" x-cloak x-bind:class="imageTotalProblem() ? 'font-semibold text-status-red' : 'text-app-text-2'">
                                    <span x-text="images.length"></span> / {{ $imageRules['max'] }} images · <span x-text="size(imageBytes())"></span> of {{ $imageRules['totalMb'] }} MB
                                </p>
                            </div>

                            @if ($imageErrors->isNotEmpty())
                                <ul class="mt-2 space-y-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert" id="images-error">
                                    @foreach ($imageErrors as $message)
                                        <li class="flex items-start gap-1"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span class="min-w-0 break-words">{{ $message }}</span></li>
                                    @endforeach
                                </ul>
                            @endif

                            @if ($errors->any())
                                <x-ui.notice class="mt-2"><p>Images are not kept when something needs fixing. If you added images, please choose them again before you send.</p></x-ui.notice>
                            @endif

                            <div class="mt-3 flex flex-col items-center rounded-card border-2 border-dashed border-app-border bg-neutral-50 p-5 text-center transition-colors duration-300 hover:bg-neutral-100 dark:bg-white/5 dark:hover:bg-white/10"
                                x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false" x-on:drop.prevent="dropImages($event)"
                                x-bind:class="dragging ? '!border-brand !bg-brand/5' : ''">
                                <span class="grid size-12 place-items-center rounded-full bg-app-card text-brand shadow-sm" aria-hidden="true"><x-ui.icon name="add_photo_alternate" :size="28" /></span>
                                <p class="mt-2 text-sm text-app-text-2">{{ $imageRules['types'] }} · up to {{ $imageRules['maxMb'] }} MB each · up to {{ $imageRules['max'] }} images and {{ $imageRules['totalMb'] }} MB in total.</p>
                                <label for="images" class="sr-only">Reference images</label>
                                <input id="images" type="file" name="images[]" multiple x-ref="images" x-on:change.stop="readImages(); problem = ''; sync()"
                                    accept="{{ collect($imageRules['extensions'])->map(fn ($ext) => '.'.$ext)->join(',') }}"
                                    @if ($imageErrors->isNotEmpty()) aria-invalid="true" aria-describedby="images-error" @endif
                                    class="mt-3 block w-full max-w-md cursor-pointer rounded-control text-sm text-app-text-2 file:mr-3 file:h-11 file:cursor-pointer file:rounded-control file:border-0 file:bg-brand file:px-5 file:text-sm file:font-semibold file:text-white file:transition-colors hover:file:bg-brand-deep focus-ring">
                            </div>

                            <p class="mt-2 flex items-start gap-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert" x-cloak x-show="imageTotalProblem()">
                                <x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span x-text="imageTotalProblem()"></span>
                            </p>

                            <ul class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4" x-cloak x-show="images.length > 0" aria-label="Chosen images">
                                <template x-for="(image, index) in images" x-bind:key="image.key">
                                    <li class="min-w-0">
                                        <div class="group relative aspect-square overflow-hidden rounded-card bg-neutral-100 shadow-sm dark:bg-white/5"
                                            x-bind:class="image.error ? 'ring-2 ring-status-red' : ''">
                                            <img x-bind:src="image.url" alt="" class="size-full object-cover" x-show="image.url && !image.error" x-on:error="image.url = ''">
                                            <span class="grid size-full place-items-center text-status-red" x-show="image.error" aria-hidden="true"><x-ui.icon name="broken_image" :size="32" /></span>
                                            {{-- The browser could not draw it; the server checks the real content when the request is sent --}}
                                            <span class="grid size-full place-items-center p-2 text-center text-xs text-app-text-2" x-show="!image.url && !image.error">
                                                <span><x-ui.icon name="image_not_supported" :size="28" class="mx-auto mb-1" />No preview</span>
                                            </span>
                                        </div>
                                        <p class="mt-1 truncate text-xs font-semibold" x-text="image.name"></p>
                                        <p class="text-xs" x-bind:class="image.error ? 'text-status-red' : 'text-app-text-2'" x-text="image.error || image.size"></p>
                                        <button type="button" x-on:click="removeImage(index)" x-bind:aria-label="`Remove ${image.name}`"
                                            class="mt-1 inline-flex min-h-11 items-center gap-1 rounded-lg px-2 text-sm font-semibold text-status-red transition-colors hover:bg-status-red-bg focus-ring sm:min-h-9">
                                            <x-ui.icon name="delete" :size="18" />Remove
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        @include('customer.printing._step-nav', ['number' => 4])
                    </section>

                    {{-- Step 5: prints preference. Nothing is ordered or priced here --}}
                    <section id="step-5" aria-labelledby="step-5-title" x-show="step === 5"
                        @if ($startStep !== 5) data-js-hidden x-bind:data-js-hidden="false" @endif
                        class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-3 shadow-sm sm:p-6 dark:shadow-none">
                        @include('customer.printing._step-header', ['number' => 5, 'total' => 6, 'title' => 'Prints', 'text' => 'Tell the studio whether you may want your photos printed.', 'pill' => 'No charge now'])

                        @error('prints')
                            <p class="mb-4 flex items-start gap-1 text-sm text-status-red"><x-ui.icon name="error" :size="16" class="mt-0.5" /><span>{{ $message }}</span></p>
                        @enderror

                        <fieldset>
                            <legend class="sr-only">Prints</legend>
                            <div class="grid gap-3 md:grid-cols-2">
                                @foreach (['digital' => ['Digital only', 'cloud_download', 'You receive your edited photos as digital files.'], 'maybe' => ['I may want prints', 'photo_library', 'The studio knows you may order prints after the shoot.']] as $value => [$title, $icon, $text])
                                    <label class="group flex cursor-pointer flex-col rounded-card border border-app-border bg-neutral-50 p-4 transition-[background-color,border-color,box-shadow] duration-300 hover:bg-neutral-100 has-checked:border-brand has-checked:bg-app-card has-checked:shadow-md has-checked:ring-2 has-checked:ring-brand has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand dark:bg-white/5 dark:hover:bg-white/10 dark:has-checked:bg-white/10">
                                        <input type="radio" name="prints" value="{{ $value }}" class="sr-only" data-name="{{ $title }}" @checked(old('prints', 'digital') === $value)>
                                        <span class="flex items-center gap-3">
                                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-neutral-200 text-app-text-2 transition-colors group-has-checked:bg-brand group-has-checked:text-white dark:bg-white/10" aria-hidden="true"><x-ui.icon :name="$icon" :size="20" /></span>
                                            <span class="font-semibold">{{ $title }}</span>
                                        </span>
                                        <span class="mt-2 text-sm text-app-text-2">{{ $text }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <x-ui.notice class="mt-4">
                            <p class="font-semibold">Prints are ordered after the shoot</p>
                            <p class="text-app-text-2">There are no photos to print yet. Once your shoot is done, you can order prints separately, and they are priced separately. This choice does not change the photography fee.</p>
                        </x-ui.notice>

                        @include('customer.printing._step-nav', ['number' => 5])
                    </section>

                    {{-- Step 6: review and send --}}
                    <section id="step-6" aria-labelledby="step-6-title" x-show="step === 6"
                        @if ($startStep !== 6) data-js-hidden x-bind:data-js-hidden="false" @endif
                        class="scroll-mt-28 rounded-card border border-app-border bg-app-card p-3 shadow-sm sm:p-6 dark:shadow-none">
                        @include('customer.printing._step-header', ['number' => 6, 'total' => 6, 'title' => 'Review and send', 'text' => 'Check your request. The studio reviews it before anything is confirmed.', 'pill' => 'Last step'])

                        @error('booking')
                            <p class="mb-4 flex items-start gap-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span>{{ $message }}</span></p>
                        @enderror

                        <p class="text-sm text-app-text-2" data-no-js-only>Check your choices in the steps above, then send your request.</p>

                        <dl class="divide-y divide-app-border rounded-card bg-neutral-100 text-sm dark:bg-white/5 [&>div]:min-w-0" x-cloak>
                            @foreach ([
                                [1, 'Package', "summary.package || 'No package chosen yet'", "summary.price"],
                                [3, 'Date and time', "summary.date || 'No time chosen yet'", "summary.time ? summary.time + ' · Philippine time' : ''"],
                                [2, 'Your details', "summary.people", "summary.contact"],
                                [4, 'Backdrop', "summary.theme || 'No listed backdrop'", "summary.idea"],
                                [5, 'Prints', "summary.prints", "''"],
                            ] as [$editStep, $title, $main, $sub])
                                <div class="flex items-start justify-between gap-3 p-4">
                                    <div class="min-w-0">
                                        <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">{{ $title }}</dt>
                                        <dd class="mt-0.5 break-words font-semibold" x-text="{{ $main }}"></dd>
                                        <dd class="mt-0.5 whitespace-pre-line break-words text-app-text-2" x-show="{{ $sub }}" x-text="{{ $sub }}"></dd>
                                    </div>
                                    <a href="#step-{{ $editStep }}" x-on:click.prevent="goTo({{ $editStep }})"
                                        class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-lg px-2 text-sm font-semibold text-brand hover:bg-brand/5 focus-ring sm:min-h-9">
                                        <x-ui.icon name="edit" :size="16" />Edit<span class="sr-only"> {{ strtolower($title) }}</span>
                                    </a>
                                </div>
                            @endforeach
                            <div class="flex items-start justify-between gap-3 p-4">
                                <div class="min-w-0">
                                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Reference images</dt>
                                    <dd class="mt-0.5 text-app-text-2" x-show="!images.length">None</dd>
                                    <dd x-show="images.length">
                                        <ul class="space-y-0.5"><template x-for="image in images" x-bind:key="image.key"><li class="truncate" x-text="image.name"></li></template></ul>
                                    </dd>
                                </div>
                                <a href="#step-4" x-on:click.prevent="goTo(4)" class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-lg px-2 text-sm font-semibold text-brand hover:bg-brand/5 focus-ring sm:min-h-9">
                                    <x-ui.icon name="edit" :size="16" />Edit<span class="sr-only"> reference images</span>
                                </a>
                            </div>
                            <div class="flex items-start justify-between gap-3 p-4">
                                <div class="min-w-0">
                                    <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Notes</dt>
                                    <dd class="mt-0.5 whitespace-pre-line break-words" x-text="summary.notes || 'None'" x-bind:class="summary.notes ? '' : 'text-app-text-2'"></dd>
                                </div>
                                <a href="#step-2" x-on:click.prevent="goTo(2)" class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-lg px-2 text-sm font-semibold text-brand hover:bg-brand/5 focus-ring sm:min-h-9">
                                    <x-ui.icon name="edit" :size="16" />Edit<span class="sr-only"> notes</span>
                                </a>
                            </div>
                        </dl>

                        <x-ui.notice variant="payment" class="mt-4">
                            <p class="text-app-text-2">Payment is made face to face at the studio. Nothing is charged online. Sending a request does not confirm the booking: the studio confirms the time after reviewing it.</p>
                        </x-ui.notice>

                        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <x-ui.button variant="outline" x-cloak x-on:click="back()">
                                <x-ui.icon name="arrow_back" :size="18" />Back to prints
                            </x-ui.button>
                            <button type="submit" @disabled($atLimit) x-bind:disabled="submitting || atLimit" x-bind:aria-busy="submitting.toString()"
                                @if ($atLimit) aria-describedby="limit-notice" @endif
                                class="btn-sweep inline-flex h-11 items-center justify-center gap-2 rounded-control px-8 text-sm font-semibold text-white shadow-md hover:[transform:scale(1.01)] focus-ring active:[transform:scale(0.98)] disabled:pointer-events-none disabled:opacity-60">
                                <x-ui.aperture-spinner :size="18" x-cloak x-show="submitting" />
                                <span x-text="submitting ? 'Sending your request…' : 'Send booking request'">Send booking request</span>
                                <x-ui.icon name="send" :size="18" x-show="!submitting" />
                            </button>
                        </div>
                        @if ($atLimit)
                            <p class="mt-2 text-sm text-status-red">{{ $limitMessage }}</p>
                        @endif
                    </section>
                </div>

                {{-- Your request so far (the design's reservation card). Only what the customer chose; no invented details --}}
                <aside class="lg:sticky lg:top-28 lg:col-span-4" aria-labelledby="summary-title">
                    <div class="rounded-card border border-app-border bg-app-card p-4 shadow-md sm:p-6 dark:shadow-none">
                        <div class="flex items-center justify-between gap-3 border-b border-app-border pb-3">
                            <h2 id="summary-title" class="flex items-center gap-2 font-semibold"><x-ui.icon name="calendar_today" :size="20" class="text-brand" />Your request</h2>
                            <span class="rounded-full bg-brand/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-brand">Not sent yet</span>
                        </div>

                        <p class="mt-3 text-sm text-app-text-2" data-no-js-only>Your choices are checked when you send the request.</p>

                        <div class="mt-3 space-y-2 text-sm" x-cloak>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="break-words font-semibold" x-text="summary.package || 'No package chosen yet'"></p>
                                    <p class="font-mono text-xs text-app-text-2" x-show="summary.minutes" x-text="summary.minutes"></p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-3 rounded-lg bg-neutral-100 px-3 py-2 dark:bg-white/5">
                                <span class="flex items-center gap-1.5 text-app-text-2"><x-ui.icon name="event" :size="16" class="text-brand" />Date</span>
                                <span class="text-right font-semibold" x-text="summary.date || '—'"></span>
                            </div>
                            <div class="flex items-center justify-between gap-3 rounded-lg bg-neutral-100 px-3 py-2 dark:bg-white/5">
                                <span class="flex items-center gap-1.5 text-app-text-2"><x-ui.icon name="schedule" :size="16" class="text-brand" />Time</span>
                                <span class="text-right font-semibold" x-text="summary.time || '—'"></span>
                            </div>
                            <div class="flex items-center justify-between gap-3 rounded-lg bg-neutral-100 px-3 py-2 dark:bg-white/5">
                                <span class="flex items-center gap-1.5 text-app-text-2"><x-ui.icon name="palette" :size="16" class="text-brand" />Backdrop</span>
                                <span class="min-w-0 truncate text-right font-semibold" x-text="summary.theme || (summary.idea ? 'Your own idea' : '—')"></span>
                            </div>
                            <div class="flex items-center justify-between gap-3 rounded-lg bg-neutral-100 px-3 py-2 dark:bg-white/5">
                                <span class="flex items-center gap-1.5 text-app-text-2"><x-ui.icon name="photo_library" :size="16" class="text-brand" />Prints</span>
                                <span class="text-right font-semibold" x-text="summary.prints"></span>
                            </div>

                            <div class="space-y-1 border-t border-app-border pt-3">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="text-app-text-2">Estimated price</span>
                                    <span class="text-right font-bold tabular-nums text-brand" x-bind:class="summary.price && summary.price.startsWith('₱') ? 'text-2xl' : 'text-base'" x-text="summary.price || '—'"></span>
                                </div>
                                <div class="flex items-center justify-between gap-3 text-xs text-app-text-2">
                                    <span>Confirmed price</span>
                                    <span class="rounded bg-neutral-100 px-2 py-0.5 dark:bg-white/10">After the studio reviews it</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">
                            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-brand"><x-ui.icon name="payments" :size="16" />Pay at the studio</p>
                            <p class="mt-1">Nothing is charged online. You pay face to face at Jhunlea Photography &amp; Printing.</p>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    @endif
@endsection
