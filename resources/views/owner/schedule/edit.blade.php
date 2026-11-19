@extends('layouts.owner')

@section('title', 'Photoshoot hours and booking rules')

@php
    use App\Http\Requests\Owner\Schedule\SaveHoursRequest;
    use App\Http\Requests\Owner\Schedule\SaveSettingsRequest;

    $step = (int) config('booking.slot_step_minutes');
    $hasOld = session()->hasOldInput();
    $hints = [
        'buffer_minutes' => 'Free minutes kept before and after every booking that holds time.',
        'min_notice_days' => 'How many days ahead a customer must book. 1 means from tomorrow; same-day photoshoots are never allowed. 1 to 60.',
        'max_advance_days' => 'How many days ahead the booking calendar opens.',
        'request_hold_hours' => 'A new request, or a time you propose, holds its time this long while it waits for an answer. After that the time is free for others again; the request stays for you.',
    ];
    $units = ['buffer_minutes' => 'minutes', 'min_notice_days' => 'days', 'max_advance_days' => 'days', 'request_hold_hours' => 'hours'];
    [$limitMin, $limitMax] = config('booking.limits.'.SaveSettingsRequest::DAILY_LIMIT);
@endphp

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('owner.schedule.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Schedule</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">Photoshoot hours and booking rules</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-2xl">
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Studio schedule</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Photoshoot hours and booking rules</h1>
            <p class="mt-2 text-app-text-2">When photoshoots can be booked. What you save here is used at once by the booking calendar customers see. Changing it never cancels or moves a booking. The shop's own opening hours are a separate text in <a href="{{ route('owner.settings.index') }}#business" class="font-semibold text-brand underline-offset-2 hover:underline focus-ring">Settings, Business info</a>.</p>
        </div>
        <x-ui.button :href="route('owner.schedule.index')" variant="outline"><x-ui.icon name="calendar_month" :size="18" />Open the calendar</x-ui.button>
    </div>

    @if ($errors->any())
        <ul class="mt-6 space-y-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert">
            @foreach ($errors->all() as $message)
                <li class="flex items-start gap-1"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span class="min-w-0 break-words">{{ $message }}</span></li>
            @endforeach
        </ul>
    @endif

    <div class="mt-6 grid items-start gap-6 2xl:grid-cols-2">
        {{-- Weekly photoshoot hours (availability_rules) --}}
        <section id="hours" class="scroll-mt-24 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="hours-title">
            <h2 id="hours-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="schedule" :size="22" class="text-brand" />Weekly photoshoot hours</h2>
            <p class="mt-1 text-sm text-app-text-2">Philippine time. Sessions start every {{ $step }} minutes from the first hour and must end by the last. The studio is one room: one photoshoot at a time.</p>
            <form method="POST" action="{{ route('owner.schedule.hours') }}" class="mt-4">
                @csrf
                <div class="divide-y divide-app-border rounded-card border border-app-border">
                    @foreach (SaveHoursRequest::DAYS as $weekday => $name)
                        @php
                            $rule = $rules[$weekday];
                            $open = $hasOld ? (bool) old("hours.{$weekday}.open") : (bool) $rule->is_open;
                            $opens = $hasOld ? old("hours.{$weekday}.opens") : ($rule->opens_at ? substr($rule->opens_at, 0, 5) : '');
                            $closes = $hasOld ? old("hours.{$weekday}.closes") : ($rule->closes_at ? substr($rule->closes_at, 0, 5) : '');
                        @endphp
                        <fieldset @class(['grid gap-3 p-3 sm:grid-cols-[10rem_minmax(0,1fr)_minmax(0,1fr)] sm:items-end', 'bg-status-red-bg/40' => $errors->has("hours.{$weekday}")])>
                            <legend class="sr-only">{{ $name }}</legend>
                            <div class="sm:pb-1">
                                <input type="hidden" name="hours[{{ $weekday }}][open]" value="0">
                                <x-ui.checkbox id="open-{{ $weekday }}" name="hours[{{ $weekday }}][open]" value="1" :checked="$open"><span class="font-semibold">{{ $name }}</span> <span class="text-app-text-2">photoshoots</span></x-ui.checkbox>
                            </div>
                            <x-ui.time-picker id="opens-{{ $weekday }}" name="hours[{{ $weekday }}][opens]" :step="$step" label="From" :value="$opens" :error="$errors->first('hours.'.$weekday)" />
                            <x-ui.time-picker id="closes-{{ $weekday }}" name="hours[{{ $weekday }}][closes]" :step="$step" label="Until" :value="$closes" :error="false" />
                        </fieldset>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-app-text-2">A day that is not ticked has no photoshoots; its times are not saved.</p>
                @include('owner.schedule._impact', ['section' => 'hours'])
                <x-ui.button type="submit" class="mt-4"><x-ui.icon name="save" :size="18" />Save photoshoot hours</x-ui.button>
            </form>
        </section>

        <div class="space-y-6">
            {{-- Booking settings --}}
            <section id="settings" class="scroll-mt-24 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="settings-title">
                <h2 id="settings-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="tune" :size="22" class="text-brand" />Booking rules</h2>
                <form method="POST" action="{{ route('owner.schedule.settings') }}" class="mt-4">
                    @csrf
                    <div class="form-grid sm:grid-cols-2">
                        @foreach (SaveSettingsRequest::FIELDS as $field => $label)
                            @php [$min, $max] = config("booking.limits.{$field}"); @endphp
                            <x-ui.input id="setting-{{ $field }}" name="{{ $field }}" type="number" inputmode="numeric" min="{{ $min }}" max="{{ $max }}" step="1" :required="true"
                                label="{{ $label }} ({{ $units[$field] }})" value="{{ old($field, $settings->{$field}) }}"
                                hint="{{ $hints[$field] }} {{ $min }} to {{ $max }}." />
                        @endforeach
                        <x-ui.input id="setting-max_sessions_per_day" name="max_sessions_per_day" type="number" inputmode="numeric" min="{{ $limitMin }}" max="{{ $limitMax }}" step="1"
                            label="{{ SaveSettingsRequest::DAILY_LIMIT_LABEL }}" value="{{ old('max_sessions_per_day', $settings->max_sessions_per_day) }}"
                            hint="Leave empty for no limit. Bookings that hold time count; a day at the limit shows as fully booked. {{ $limitMin }} to {{ $limitMax }}." />
                    </div>
                    @include('owner.schedule._impact', ['section' => 'settings'])
                    <x-ui.button type="submit" class="mt-4"><x-ui.icon name="save" :size="18" />Save booking rules</x-ui.button>
                </form>
            </section>

            {{-- Blocked dates --}}
            <section id="blocked" class="scroll-mt-24 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="blocked-title">
                <h2 id="blocked-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="event_busy" :size="22" class="text-brand" />Blocked dates</h2>
                <p class="mt-1 text-sm text-app-text-2">Holidays, maintenance or any day the studio does not take sessions.</p>
                <div class="mt-4">@include('owner.schedule._block-form', ['idPrefix' => 'block'])</div>

                <h3 class="mt-6 text-sm font-semibold">Upcoming blocked dates ({{ $upcomingBlocked->count() }})</h3>
                @if ($upcomingBlocked->isEmpty())
                    <p class="mt-2 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">No days are blocked.</p>
                @else
                    <ul class="mt-2 divide-y divide-app-border rounded-card border border-app-border">
                        @foreach ($upcomingBlocked as $blockedDay)
                            <li class="flex items-center justify-between gap-3 p-3">
                                <div class="min-w-0">
                                    <p class="flex items-center gap-2 font-semibold"><span class="size-2 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>{{-- A calendar day of the shop, not a moment: no time zone to convert --}}{{ $blockedDay->date->format('D, M j, Y') }}</p>
                                    <p class="break-words text-xs text-app-text-2">{{ $blockedDay->reason ?? 'No reason given' }}</p>
                                </div>
                                <form method="POST" action="{{ route('owner.schedule.unblock', $blockedDay) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex min-h-11 items-center gap-1 rounded-control px-3 text-sm font-semibold text-status-red transition-colors hover:bg-status-red-bg focus-ring sm:min-h-9">
                                        <x-ui.icon name="delete" :size="18" />Unblock<span class="sr-only"> {{ $blockedDay->date->format('D, M j, Y') }}</span>
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
@endsection
