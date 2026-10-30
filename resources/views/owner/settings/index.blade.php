@extends('layouts.owner')

@section('title', 'Settings')

{{--
    The owner's Settings (Phase 6B), after design/admin/settings_studio_configuration_rules: numbered section tabs (a red
    pill marks the open one), one card per section, number steppers for the upload limits and a save bar at the foot of
    each form. Without JavaScript every section is shown one after the other and the tabs are plain links to them.
    Business info and upload limits are edited here; the opening hours and booking rules are only shown (the Schedule
    editor changes them); the payment rules are fixed facts, never switches.
--}}
@php
    use App\Http\Requests\Owner\Schedule\SaveHoursRequest;

    $tabs = [
        'business' => ['1. Business info', 'storefront'],
        'booking' => ['2. Booking rules', 'event_available'],
        'uploads' => ['3. Upload rules', 'upload_file'],
        'payments' => ['4. Payments', 'payments'],
        'faq' => ['5. FAQ', 'quiz'],
    ];
    $hourText = fn (?string $time) => $time ? \Illuminate\Support\Carbon::createFromFormat('H:i:s', $time)->format('g:i A') : '';
    $unit = fn (int $value, string $unit) => $value.' '.($unit === 'files' ? Str::plural('file', $value) : $unit);
    $groups = [
        'Printing orders' => ['printing_max_file_mb', 'printing_max_total_mb', 'printing_max_files'],
        'Booking reference images' => ['booking_max_image_mb', 'booking_max_total_mb', 'booking_max_images'],
    ];
    $hints = [
        'printing_max_file_mb' => 'The most one file may be. A service may set a lower limit of its own.',
        'printing_max_total_mb' => 'All files of one order together.',
        'printing_max_files' => 'How many files one order may hold.',
        'booking_max_image_mb' => 'The most one reference image may be.',
        'booking_max_total_mb' => 'All images of one booking together.',
        'booking_max_images' => 'How many images one booking may have.',
    ];
@endphp

@section('main')
    <div x-data="{
            tab: 'business',
            tabs: {{ \Illuminate\Support\Js::from(array_keys($tabs)) }},
            open(name) { this.tab = name; history.replaceState(null, '', '#' + name); },
        }"
        x-init="const hash = location.hash.slice(1); if (tabs.includes(hash)) tab = hash">
        <div>
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Settings</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Shop settings and rules</h1>
            <p class="mt-2 max-w-2xl text-app-text-2">Your business details, the upload limits and the questions customers ask most. Opening hours and booking rules are changed in the Schedule editor.</p>
        </div>

        <nav aria-label="Settings sections" class="mt-6 rounded-card border border-app-border bg-app-card p-1.5 dark:shadow-none">
            <ul class="flex flex-wrap gap-1">
                @foreach ($tabs as $key => [$label, $icon])
                    <li>
                        <a href="#{{ $key }}" x-on:click.prevent="open('{{ $key }}')"
                            x-bind:aria-current="tab === '{{ $key }}' ? 'true' : null"
                            x-bind:class="tab === '{{ $key }}' ? 'bg-brand text-white shadow-sm' : 'text-app-text-2 hover:bg-neutral-100 hover:text-app-text dark:hover:bg-white/10'"
                            class="inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-control px-3.5 text-sm font-semibold transition-all duration-200 focus-ring">
                            <x-ui.icon :name="$icon" :size="18" />{{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        {{-- 1. Business info --}}
        <section id="business" x-show="tab === 'business'" x-transition.opacity.duration.200ms class="mt-6 scroll-mt-24" aria-labelledby="business-title">
            <form method="POST" action="{{ route('owner.settings.business') }}" class="rounded-card border border-app-border bg-app-card shadow-sm transition-shadow hover:shadow-md dark:shadow-none">
                @csrf
                <div class="p-4 sm:p-6">
                    <h2 id="business-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="storefront" :size="22" class="text-brand" />Business info</h2>
                    <p class="mt-1 text-sm text-app-text-2">Shown in the footer of the public site, only for the details you fill in. Leave a field empty to hide it.</p>

                    @if ($errors->hasAny(['shop_name', 'address', 'phone', 'email']))
                        <x-ui.notice class="mt-4 !border-status-red/30 !bg-status-red-bg" role="alert">Please check the fields marked below. Nothing was saved.</x-ui.notice>
                    @endif

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <x-ui.input label="Shop name" name="shop_name" maxlength="80" :value="old('shop_name', $business['shop_name'])" hint="Up to 80 characters." />
                        </div>
                        <div class="md:col-span-2">
                            <x-ui.textarea label="Address (where customers visit)" name="address" rows="3" maxlength="300" hint="Up to 300 characters. Line breaks are kept.">{{ old('address', $business['address']) }}</x-ui.textarea>
                        </div>
                        <x-ui.input label="Phone" name="phone" type="tel" maxlength="40" autocomplete="off" :value="old('phone', $business['phone'])" hint="For example +63 917 123 4567." />
                        <x-ui.input label="Email" name="email" type="email" maxlength="120" autocomplete="off" :value="old('email', $business['email'])" hint="Up to 120 characters." />
                    </div>
                </div>
                <div class="flex flex-col gap-3 rounded-b-card border-t border-app-border bg-neutral-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:bg-white/5">
                    <p class="flex items-start gap-2 text-xs text-app-text-2"><x-ui.icon name="info" :size="16" class="mt-px shrink-0 text-brand" />Saved details appear on the public site at once.</p>
                    <x-ui.button type="submit" class="shrink-0"><x-ui.icon name="save" :size="18" />Save business info</x-ui.button>
                </div>
            </form>
        </section>

        {{-- 2. Booking rules: read only, the Schedule editor is the one place that changes them --}}
        <section id="booking" x-show="tab === 'booking'" x-transition.opacity.duration.200ms class="mt-6 scroll-mt-24" aria-labelledby="booking-title">
            <div class="rounded-card border border-app-border bg-app-card p-4 shadow-sm transition-shadow hover:shadow-md sm:p-6 dark:shadow-none">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="booking-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="event_available" :size="22" class="text-brand" />Booking rules</h2>
                        <p class="mt-1 text-sm text-app-text-2">What the booking calendar uses now. Shown here only; change them in the Schedule editor.</p>
                    </div>
                    <x-ui.button :href="route('owner.schedule.edit')" variant="outline"><x-ui.icon name="edit_calendar" :size="18" />Change in the Schedule editor</x-ui.button>
                </div>

                <div class="mt-5 grid gap-6 lg:grid-cols-2">
                    <div>
                        <h3 class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Weekly opening hours</h3>
                        <dl class="mt-2 divide-y divide-app-border rounded-control border border-app-border text-sm">
                            @foreach (SaveHoursRequest::DAYS as $weekday => $name)
                                @php($rule = $week[$weekday])
                                <div class="flex items-center justify-between gap-3 px-3 py-2">
                                    <dt class="font-semibold">{{ $name }}</dt>
                                    <dd @class(['tabular-nums', 'text-app-text-2' => ! $rule->is_open])>{{ $rule->is_open ? $hourText($rule->opens_at).' – '.$hourText($rule->closes_at) : 'Closed' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                    <div>
                        <h3 class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Requests and sessions</h3>
                        <dl class="mt-2 grid gap-3 sm:grid-cols-2">
                            @foreach ([
                                ['Notice needed', $schedule->min_notice_hours.' '.Str::plural('hour', $schedule->min_notice_hours), 'How long before a session a customer must request it.'],
                                ['Booking calendar opens', $schedule->max_advance_days.' '.Str::plural('day', $schedule->max_advance_days).' ahead', 'How far ahead customers can choose a date.'],
                                ['Buffer between sessions', $schedule->buffer_minutes.' '.Str::plural('minute', $schedule->buffer_minutes), 'Kept free before and after every confirmed session.'],
                                ['Sessions at once', (string) $schedule->concurrent_sessions, 'How many sessions the studio can run at the same moment.'],
                            ] as [$label, $value, $hint])
                                <div class="rounded-control bg-neutral-100 p-3 dark:bg-white/5">
                                    <dt class="text-xs text-app-text-2">{{ $label }}</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums">{{ $value }}</dd>
                                    <dd class="mt-1 text-xs text-app-text-2">{{ $hint }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        <h3 class="mt-5 text-[11px] font-bold uppercase tracking-wider text-app-text-2">Fixed rules</h3>
                        <ul class="mt-2 space-y-2 text-sm">
                            <li class="flex items-start gap-2"><x-ui.icon name="lock" :size="16" class="mt-0.5 shrink-0 text-app-text-2" />A customer may cancel a confirmed booking themselves until {{ $cancelCutoffHours }} {{ Str::plural('hour', $cancelCutoffHours) }} before it starts.</li>
                            <li class="flex items-start gap-2"><x-ui.icon name="lock" :size="16" class="mt-0.5 shrink-0 text-app-text-2" />A customer may have at most {{ $maxOpenRequests }} {{ Str::plural('request', $maxOpenRequests) }} waiting for an answer at the same time.</li>
                            <li class="flex items-start gap-2"><x-ui.icon name="lock" :size="16" class="mt-0.5 shrink-0 text-app-text-2" />A requested time is not a confirmed session until you confirm it.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        {{-- 3. Upload rules --}}
        <section id="uploads" x-show="tab === 'uploads'" x-transition.opacity.duration.200ms class="mt-6 scroll-mt-24 space-y-6" aria-labelledby="uploads-title">
            <form method="POST" action="{{ route('owner.settings.uploads') }}" class="rounded-card border border-app-border bg-app-card shadow-sm transition-shadow hover:shadow-md dark:shadow-none">
                @csrf
                <div class="p-4 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 id="uploads-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="upload_file" :size="22" class="text-brand" />Upload rules</h2>
                            <p class="mt-1 text-sm text-app-text-2">New uploads use these limits at once. Files already sent are never changed or removed.</p>
                        </div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-3 py-1 font-mono text-xs text-app-text-2 dark:bg-white/10">{{ $usesDefaults ? 'Starting defaults' : 'Your own limits' }}</span>
                    </div>

                    @if ($errors->hasAny(array_keys($limits)))
                        <x-ui.notice class="mt-4 !border-status-red/30 !bg-status-red-bg" role="alert">Please check the limits marked below. Nothing was saved.</x-ui.notice>
                    @endif
                    @if (collect($limits)->contains('capped', true))
                        <x-ui.notice class="mt-4" role="status">Some saved limits are above what this server accepts now, so the server's maximum is used. Save again to store the lower value, or raise the server limits in php.ini.</x-ui.notice>
                    @endif

                    @foreach ($groups as $group => $fields)
                        <h3 class="mt-6 text-[11px] font-bold uppercase tracking-wider text-app-text-2">{{ $group }}</h3>
                        <div class="mt-2 grid gap-3 lg:grid-cols-3">
                            @foreach ($fields as $field)
                                @php($limit = $limits[$field])
                                @php($value = old($field, $limit['value']))
                                <div class="flex flex-col justify-between gap-3 rounded-control bg-neutral-100 p-3 dark:bg-white/5" x-data="{ value: {{ \Illuminate\Support\Js::from(is_numeric($value) ? (int) $value : $value) }}, min: 1, max: {{ $limit['max'] }} }">
                                    <div>
                                        <label for="limit-{{ $field }}" class="font-semibold">{{ $limit['label'] }} <span class="font-normal text-app-text-2">({{ $limit['unit'] }})</span></label>
                                        <p id="limit-{{ $field }}-hint" class="mt-0.5 text-xs text-app-text-2">{{ $hints[$field] }} From 1 to {{ $limit['max'] }}. Starting default {{ $unit($limit['default'], $limit['unit']) }}.</p>
                                        @if ($limit['capped'])
                                            <p class="mt-1 text-xs font-semibold text-status-amber">Capped: the saved value is above this server's {{ $unit($limit['max'], $limit['unit']) }}.</p>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-1 self-start rounded-control border border-app-border bg-app-card p-1">
                                        <button type="button" class="hidden size-11 place-items-center rounded-lg text-app-text transition-colors hover:bg-neutral-100 focus-ring disabled:opacity-40 dark:hover:bg-white/10" x-bind:class="'!grid'"
                                            x-on:click="value = Math.max(min, (parseInt(value) || min) - 1)" x-bind:disabled="(parseInt(value) || 0) <= min" aria-label="One less"><x-ui.icon name="remove" :size="18" /></button>
                                        <input id="limit-{{ $field }}" name="{{ $field }}" type="number" inputmode="numeric" min="1" max="{{ $limit['max'] }}" step="1" required
                                            value="{{ $value }}" x-model="value" aria-describedby="limit-{{ $field }}-hint @error($field) limit-{{ $field }}-error @enderror"
                                            @error($field) aria-invalid="true" @enderror
                                            class="h-11 w-20 rounded-lg border-0 bg-transparent text-center font-mono font-bold tabular-nums text-app-text focus-ring">
                                        <button type="button" class="hidden size-11 place-items-center rounded-lg text-app-text transition-colors hover:bg-neutral-100 focus-ring disabled:opacity-40 dark:hover:bg-white/10" x-bind:class="'!grid'"
                                            x-on:click="value = Math.min(max, (parseInt(value) || 0) + 1)" x-bind:disabled="(parseInt(value) || 0) >= max" aria-label="One more"><x-ui.icon name="add" :size="18" /></button>
                                    </div>
                                    @error($field)
                                        <p id="limit-{{ $field }}-error" class="text-xs font-semibold text-status-red">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    @endforeach

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <div class="rounded-control border border-app-border p-3 text-sm">
                            <h3 class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Accepted file types (fixed)</h3>
                            <p class="mt-2"><span class="font-semibold">Printing:</span> <span class="font-mono uppercase">{{ implode(', ', $printingTypes) }}</span></p>
                            <p class="mt-1"><span class="font-semibold">Reference images:</span> <span class="font-mono uppercase">{{ implode(', ', $bookingTypes) }}</span></p>
                            <p class="mt-2 text-xs text-app-text-2">Each printing service chooses which of these it takes. The list itself is fixed for safety.</p>
                        </div>
                        <div class="rounded-control border border-app-border p-3 text-sm">
                            <h3 class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">This server accepts</h3>
                            <p class="mt-2 font-mono text-xs">upload_max_filesize = {{ $server['file'] ?: 'not set' }} · post_max_size = {{ $server['post'] ?: 'not set' }} · max_file_uploads = {{ $server['files'] ?: 'not set' }}</p>
                            <p class="mt-2 text-xs text-app-text-2">A limit can never go above these. To allow bigger uploads, raise them in php.ini first (the total keeps 2 MB free for the rest of the form).</p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col gap-3 rounded-b-card border-t border-app-border bg-neutral-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:bg-white/5">
                    <p class="flex items-start gap-2 text-xs text-app-text-2"><x-ui.icon name="info" :size="16" class="mt-px shrink-0 text-brand" />Each total must be at least its per-file limit.</p>
                    <x-ui.button type="submit" class="shrink-0"><x-ui.icon name="save" :size="18" />Save upload rules</x-ui.button>
                </div>
            </form>

            @unless ($usesDefaults)
                <form method="POST" action="{{ route('owner.settings.uploads.reset') }}" class="flex flex-col gap-3 rounded-card border border-app-border bg-app-card p-4 sm:flex-row sm:items-center sm:justify-between dark:shadow-none">
                    @csrf
                    <x-ui.checkbox name="confirm" value="1">Yes, use the starting defaults for every upload limit</x-ui.checkbox>
                    <x-ui.button type="submit" variant="outline" class="shrink-0"><x-ui.icon name="restart_alt" :size="18" />Use the starting defaults</x-ui.button>
                </form>
            @endunless
        </section>

        {{-- 4. Payments: fixed shop rules, never switches --}}
        <section id="payments" x-show="tab === 'payments'" x-transition.opacity.duration.200ms class="mt-6 scroll-mt-24" aria-labelledby="payments-title">
            <div class="rounded-card border border-app-border bg-app-card p-4 shadow-sm transition-shadow hover:shadow-md sm:p-6 dark:shadow-none">
                <h2 id="payments-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="payments" :size="22" class="text-brand" />Payments</h2>
                <p class="mt-1 text-sm text-app-text-2">These are the shop's fixed rules. They cannot be changed here.</p>
                <ul class="mt-4 space-y-3 text-sm">
                    <li class="flex items-start gap-3 rounded-control bg-neutral-100 p-3 dark:bg-white/5"><x-ui.icon name="storefront" :size="20" class="mt-px shrink-0 text-brand" />Payment is made face to face at the studio (cash or GCash). The system only records payments. Nothing is charged online.</li>
                    <li class="flex items-start gap-3 rounded-control bg-neutral-100 p-3 dark:bg-white/5"><x-ui.icon name="verified" :size="20" class="mt-px shrink-0 text-brand" />Full payment is required before an order or the photos are released.</li>
                </ul>
            </div>
        </section>

        {{-- 5. FAQ --}}
        <section id="faq" x-show="tab === 'faq'" x-transition.opacity.duration.200ms class="mt-6 scroll-mt-24" aria-labelledby="faq-title">
            <div class="flex flex-col gap-4 rounded-card border border-app-border bg-app-card p-4 shadow-sm transition-shadow hover:shadow-md sm:flex-row sm:items-center sm:justify-between sm:p-6 dark:shadow-none">
                <div>
                    <h2 id="faq-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="quiz" :size="22" class="text-brand" />Frequently asked questions</h2>
                    <p class="mt-1 text-sm text-app-text-2">{{ $faqPublished }} {{ Str::plural('question', $faqPublished) }} shown on the public FAQ page{{ $faqCount > $faqPublished ? ', '.($faqCount - $faqPublished).' hidden' : '' }}.</p>
                </div>
                <x-ui.button :href="route('owner.faqs.index')" class="shrink-0"><x-ui.icon name="edit_note" :size="18" />Open the FAQ editor</x-ui.button>
            </div>
        </section>
    </div>
@endsection
