@extends('layouts.owner')

@section('title', 'Opening hours and booking rules')

@php
    use App\Http\Requests\Owner\Schedule\SaveHoursRequest;
    use App\Http\Requests\Owner\Schedule\SaveSettingsRequest;

    $step = (int) config('booking.slot_step_minutes');
    $hasOld = session()->hasOldInput();
    $hints = [
        'buffer_minutes' => 'Free minutes kept before and after every confirmed session.',
        'min_notice_hours' => 'How long before a session a customer must request it.',
        'max_advance_days' => 'How many days ahead the booking calendar opens.',
        'concurrent_sessions' => 'How many sessions the studio can run at the same moment.',
    ];
    $units = ['buffer_minutes' => 'minutes', 'min_notice_hours' => 'hours', 'max_advance_days' => 'days', 'concurrent_sessions' => 'sessions'];
@endphp

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('owner.schedule.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Schedule</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">Hours and booking rules</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-2xl">
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Studio schedule</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Opening hours and booking rules</h1>
            <p class="mt-2 text-app-text-2">What you save here is used at once by the booking calendar customers see. Changing it never cancels or moves a booking.</p>
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
        {{-- Weekly opening hours --}}
        <section id="hours" class="scroll-mt-24 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="hours-title">
            <h2 id="hours-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="schedule" :size="22" class="text-brand" />Weekly opening hours</h2>
            <p class="mt-1 text-sm text-app-text-2">Shop time (Philippine time). Sessions start every {{ $step }} minutes from opening and must end by closing.</p>
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
                        <fieldset @class(['grid gap-3 p-3 sm:grid-cols-[9rem_minmax(0,1fr)_minmax(0,1fr)] sm:items-center', 'bg-status-red-bg/40' => $errors->has("hours.{$weekday}")])>
                            <legend class="sr-only">{{ $name }}</legend>
                            <div>
                                <input type="hidden" name="hours[{{ $weekday }}][open]" value="0">
                                <x-ui.checkbox id="open-{{ $weekday }}" name="hours[{{ $weekday }}][open]" value="1" :checked="$open"><span class="font-semibold">{{ $name }}</span> <span class="text-app-text-2">open</span></x-ui.checkbox>
                            </div>
                            <x-ui.input id="opens-{{ $weekday }}" name="hours[{{ $weekday }}][opens]" type="time" step="{{ $step * 60 }}" label="Opens" value="{{ $opens }}" :error="$errors->first('hours.'.$weekday)" />
                            <x-ui.input id="closes-{{ $weekday }}" name="hours[{{ $weekday }}][closes]" type="time" step="{{ $step * 60 }}" label="Closes" value="{{ $closes }}" :error="false" />
                        </fieldset>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-app-text-2">A day that is not ticked is closed; its times are not saved.</p>
                @include('owner.schedule._impact', ['section' => 'hours'])
                <x-ui.button type="submit" class="mt-4"><x-ui.icon name="save" :size="18" />Save opening hours</x-ui.button>
            </form>
        </section>

        <div class="space-y-6">
            {{-- Booking settings --}}
            <section id="settings" class="scroll-mt-24 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="settings-title">
                <h2 id="settings-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="tune" :size="22" class="text-brand" />Booking rules</h2>
                <form method="POST" action="{{ route('owner.schedule.settings') }}" class="mt-4">
                    @csrf
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (SaveSettingsRequest::FIELDS as $field => $label)
                            @php [$min, $max] = config("booking.limits.{$field}"); @endphp
                            <x-ui.input id="setting-{{ $field }}" name="{{ $field }}" type="number" inputmode="numeric" min="{{ $min }}" max="{{ $max }}" step="1" :required="true"
                                label="{{ $label }} ({{ $units[$field] }})" value="{{ old($field, $settings->{$field}) }}"
                                hint="{{ $hints[$field] }} {{ $min }} to {{ $max }}." />
                        @endforeach
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
