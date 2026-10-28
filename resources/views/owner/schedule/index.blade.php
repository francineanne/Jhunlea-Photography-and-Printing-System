@extends('layouts.owner')

@section('title', 'Schedule')

@php
    use App\Http\Requests\Owner\Schedule\SaveHoursRequest;

    $view = $calendar['view'];
    $days = collect($calendar['days']);
    $inPeriod = $days->where('inPeriod', true);
    // The phone agenda (and the day view): days of the period with something to show
    $agenda = $inPeriod->filter(fn ($day) => $day['bookings']->isNotEmpty() || $day['blocked'] !== null);
    $link = fn (string $view, string $date) => route('owner.schedule.index', ['view' => $view, 'date' => $date]);
    $maxChips = 3;
@endphp

@section('main')
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-2xl">
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Studio schedule</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Schedule</h1>
            <p class="mt-2 text-app-text-2">Confirmed sessions, requests waiting for you and the days the studio is closed. Open a booking to work on it.</p>
        </div>
        <x-ui.button :href="route('owner.schedule.edit')"><x-ui.icon name="tune" :size="18" />Hours and booking rules</x-ui.button>
    </div>

    @if ($errors->any())
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </x-ui.notice>
    @endif

    {{-- Period and view controls --}}
    <div class="mt-6 flex flex-col gap-3 rounded-card border border-app-border bg-app-card p-3 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-4 dark:shadow-none">
        <div class="flex items-center gap-2">
            <a href="{{ $link($view, $calendar['previous']) }}" aria-label="Previous {{ $view }}" class="grid size-11 shrink-0 place-items-center rounded-control bg-neutral-100 text-app-text-2 transition-colors hover:text-app-text focus-ring dark:bg-white/10"><x-ui.icon name="chevron_left" :size="20" /></a>
            <h2 class="min-w-0 flex-1 text-center font-display text-xl font-semibold sm:min-w-56 sm:text-2xl" aria-live="polite">{{ $calendar['title'] }}</h2>
            <a href="{{ $link($view, $calendar['next']) }}" aria-label="Next {{ $view }}" class="grid size-11 shrink-0 place-items-center rounded-control bg-neutral-100 text-app-text-2 transition-colors hover:text-app-text focus-ring dark:bg-white/10"><x-ui.icon name="chevron_right" :size="20" /></a>
            <a href="{{ $link($view, $calendar['today']) }}" class="inline-flex min-h-11 items-center rounded-control border border-app-border px-3 text-sm font-semibold transition-colors hover:bg-neutral-100 focus-ring dark:hover:bg-white/10">Today</a>
        </div>
        <nav aria-label="Calendar view">
            <ul class="grid grid-cols-3 gap-1 rounded-control bg-neutral-100 p-1 dark:bg-white/5">
                @foreach (['month' => 'Month', 'week' => 'Week', 'day' => 'Day'] as $value => $label)
                    <li>
                        <a href="{{ $link($value, $calendar['date']) }}" @if ($view === $value) aria-current="page" @endif
                            @class(['flex min-h-11 items-center justify-center rounded-lg px-4 text-sm font-semibold transition-colors focus-ring sm:min-h-9',
                                'bg-brand text-white shadow-sm' => $view === $value,
                                'text-app-text-2 hover:text-app-text' => $view !== $value])>{{ $label }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>

    <div class="mt-6 grid items-start gap-6 min-[1400px]:grid-cols-[minmax(0,1fr)_19rem]">
        <div class="min-w-0">
            {{-- The grid: month and week, from 1280px. Smaller screens and the day view use the agenda below --}}
            @if ($view !== 'day')
                <div class="hidden rounded-card border border-app-border bg-app-card p-3 shadow-sm xl:block dark:shadow-none">
                    <div class="grid grid-cols-7 gap-1.5 pb-2 text-center text-[11px] font-bold uppercase tracking-wider text-app-text-2" aria-hidden="true">
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                            <span @class(['rounded-lg py-1', 'bg-status-red-bg text-brand' => $weekday === 'Sat'])>{{ $weekday }}</span>
                        @endforeach
                    </div>
                    <ol class="grid grid-cols-7 gap-1.5" aria-label="{{ $calendar['title'] }}">
                        @foreach ($days as $day)
                            @php
                                $count = $day['bookings']->count();
                                $shown = $view === 'week' ? $day['bookings'] : $day['bookings']->take($maxChips);
                                $more = $count - $shown->count();
                            @endphp
                            <li @class([
                                'flex flex-col gap-1 rounded-card border p-1.5 transition-colors',
                                $view === 'week' ? 'min-h-72' : 'min-h-32',
                                'opacity-40' => ! $day['inPeriod'],
                                'border-brand ring-1 ring-brand' => $day['today'],
                                'border-status-red/30 bg-status-red-bg/50' => $day['blocked'] !== null && ! $day['today'],
                                'border-app-border bg-neutral-100 dark:bg-white/5' => $day['blocked'] === null && $day['closed'] && ! $day['today'],
                                'border-app-border bg-app-bg' => $day['blocked'] === null && ! $day['closed'] && ! $day['today'],
                            ])>
                                <div class="flex items-center justify-between gap-1">
                                    <a href="{{ $link('day', $day['date']) }}" class="inline-flex min-h-7 items-center gap-1 rounded px-1 font-mono text-xs font-bold hover:text-brand focus-ring {{ $day['today'] ? 'text-brand' : '' }}"
                                        aria-label="{{ \Illuminate\Support\Carbon::parse($day['date'])->format('l, F j') }}: {{ $count }} {{ Str::plural('booking', $count) }}">
                                        {{ (int) substr($day['date'], 8, 2) }}
                                        @if ($day['today'])<span class="rounded bg-brand px-1 text-[9px] uppercase text-white">Today</span>@endif
                                    </a>
                                    @if ($day['blocked'] !== null || $day['closed'])
                                        <x-ui.icon name="lock" :size="14" class="text-app-text-2" />
                                    @elseif ($count > 1)
                                        <span class="font-mono text-[10px] text-app-text-2">{{ $count }} sessions</span>
                                    @endif
                                </div>
                                @foreach ($shown as $booking)
                                    @include('owner.schedule._chip', ['booking' => $booking, 'full' => $view === 'week'])
                                @endforeach
                                @if ($more > 0)
                                    <a href="{{ $link('day', $day['date']) }}" class="rounded px-1 text-[11px] font-semibold text-brand hover:underline focus-ring">+{{ $more }} more</a>
                                @endif
                                @if ($day['blocked'] !== null)
                                    <p class="mt-auto break-words rounded-lg bg-brand px-1.5 py-1 text-[10px] font-semibold leading-tight text-white">Blocked: {{ $day['blocked'] }}</p>
                                @elseif ($day['closed'])
                                    <p class="mt-auto text-[10px] font-semibold uppercase tracking-wider text-app-text-2">Closed</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            {{-- The agenda: the day view, and on smaller screens the whole period as a list --}}
            <div @class(['space-y-3', 'xl:hidden' => $view !== 'day'])>
                @forelse ($view === 'day' ? $inPeriod : $agenda as $day)
                    <section class="rounded-card border border-app-border bg-app-card p-3 shadow-sm sm:p-4 dark:shadow-none {{ $day['today'] ? 'ring-1 ring-brand' : '' }}" aria-label="{{ \Illuminate\Support\Carbon::parse($day['date'])->format('l, F j') }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <a href="{{ $link('day', $day['date']) }}" class="inline-flex min-h-11 items-center gap-2 rounded font-semibold hover:text-brand focus-ring">
                                {{ \Illuminate\Support\Carbon::parse($day['date'])->format('D, M j, Y') }}
                                @if ($day['today'])<span class="rounded bg-brand px-1.5 py-0.5 text-[10px] font-bold uppercase text-white">Today</span>@endif
                            </a>
                            @if ($day['blocked'] !== null)
                                <span class="rounded bg-status-red-bg px-2 py-0.5 text-xs font-semibold text-status-red">Blocked: {{ $day['blocked'] }}</span>
                            @elseif ($day['closed'])
                                <span class="rounded bg-neutral-100 px-2 py-0.5 text-xs font-semibold text-app-text-2 dark:bg-white/10">Closed</span>
                            @endif
                        </div>
                        @if ($day['bookings']->isEmpty())
                            <p class="mt-2 text-sm text-app-text-2">No sessions or requests.</p>
                        @else
                            <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($day['bookings'] as $booking)
                                    <li>@include('owner.schedule._chip', ['booking' => $booking, 'full' => true])</li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @empty
                    <x-ui.card>
                        <x-ui.empty-state icon="event_available" title="Nothing in this {{ $view }}" description="No sessions, requests or blocked days. Use the arrows to look at another {{ $view }}." />
                    </x-ui.card>
                @endforelse
            </div>

            {{-- Legend, as in the design --}}
            <div class="mt-4 rounded-card border border-app-border bg-app-card p-4 text-xs dark:shadow-none">
                <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Legend</p>
                <ul class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                    <li class="flex items-center gap-1.5"><span class="size-3 rounded bg-ink dark:bg-white" aria-hidden="true"></span>Confirmed session</li>
                    <li class="flex items-center gap-1.5"><span class="size-3 rounded bg-neutral-300 dark:bg-white/30" aria-hidden="true"></span>Completed</li>
                    <li class="flex items-center gap-1.5"><span class="size-3 rounded border border-dashed border-status-amber bg-status-amber-bg" aria-hidden="true"></span>Request waiting for you</li>
                    <li class="flex items-center gap-1.5"><span class="size-3 rounded border border-dashed border-status-blue bg-status-blue-bg" aria-hidden="true"></span>New time offered</li>
                    <li class="flex items-center gap-1.5"><span class="size-3 rounded bg-brand" aria-hidden="true"></span>Blocked day</li>
                    <li class="flex items-center gap-1.5"><span class="size-3 rounded bg-neutral-100 ring-1 ring-app-border dark:bg-white/10" aria-hidden="true"></span>Closed day</li>
                </ul>
                <p class="mt-2 text-app-text-2">Times are in Philippine time. Only confirmed sessions hold a time.</p>
            </div>
        </div>

        {{-- Side panel: the rules in force and the blocked days, as in the design --}}
        <aside class="space-y-6" aria-label="Schedule rules">
            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm dark:shadow-none" aria-labelledby="hours-title">
                <div class="flex items-center justify-between gap-2">
                    <h2 id="hours-title" class="flex items-center gap-2 font-display text-lg font-semibold"><x-ui.icon name="schedule" :size="20" class="text-brand" />Opening hours</h2>
                    <a href="{{ route('owner.schedule.edit') }}#hours" class="inline-flex min-h-11 items-center rounded text-xs font-semibold text-brand hover:underline focus-ring sm:min-h-8">Edit</a>
                </div>
                <dl class="mt-3 space-y-1 text-sm">
                    @foreach (SaveHoursRequest::DAYS as $weekday => $name)
                        @php $rule = $rules[$weekday]; @endphp
                        <div class="flex justify-between gap-2 rounded-lg px-2 py-1 {{ $rule->is_open ? '' : 'bg-neutral-100 text-app-text-2 dark:bg-white/5' }}">
                            <dt>{{ $name }}</dt>
                            <dd class="font-mono text-xs">{{ $rule->is_open ? substr($rule->opens_at, 0, 5).'–'.substr($rule->closes_at, 0, 5) : 'Closed' }}</dd>
                        </div>
                    @endforeach
                </dl>
                <dl class="mt-3 space-y-1 border-t border-app-border pt-3 text-sm">
                    <div class="flex justify-between gap-2"><dt class="text-app-text-2">Buffer between sessions</dt><dd class="font-mono text-xs font-semibold text-brand">{{ $settings->buffer_minutes }} mins</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-app-text-2">Minimum notice</dt><dd class="font-mono text-xs font-semibold text-brand">{{ $settings->min_notice_hours }} hrs</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-app-text-2">Book ahead up to</dt><dd class="font-mono text-xs font-semibold text-brand">{{ $settings->max_advance_days }} days</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-app-text-2">Sessions at once</dt><dd class="font-mono text-xs font-semibold text-brand">{{ $settings->concurrent_sessions }}</dd></div>
                </dl>
            </section>

            <section id="blocked" class="scroll-mt-24 rounded-card border border-app-border bg-app-card p-4 shadow-sm dark:shadow-none" aria-labelledby="blocked-title">
                <h2 id="blocked-title" class="flex items-center gap-2 font-display text-lg font-semibold"><x-ui.icon name="event_busy" :size="20" class="text-brand" />Blocked days <span class="ml-auto font-mono text-xs font-normal text-app-text-2">{{ $upcomingBlocked->count() }} upcoming</span></h2>
                @if ($upcomingBlocked->isEmpty())
                    <p class="mt-2 text-sm text-app-text-2">No days are blocked.</p>
                @else
                    <ul class="mt-2 space-y-2">
                        @foreach ($upcomingBlocked->take(6) as $blockedDay)
                            <li class="rounded-lg bg-neutral-100 p-2 text-sm dark:bg-white/5">
                                <p class="flex items-center gap-1.5 font-semibold"><span class="size-1.5 rounded-full bg-brand" aria-hidden="true"></span>{{ $blockedDay->date->format('D, M j, Y') }}</p>
                                <p class="break-words text-xs text-app-text-2">{{ $blockedDay->reason ?? 'No reason given' }}</p>
                            </li>
                        @endforeach
                    </ul>
                    @if ($upcomingBlocked->count() > 6)
                        <a href="{{ route('owner.schedule.edit') }}#blocked" class="mt-2 inline-flex min-h-11 items-center text-xs font-semibold text-brand hover:underline focus-ring">See all {{ $upcomingBlocked->count() }}</a>
                    @endif
                @endif
                <div class="mt-4 rounded-card bg-neutral-100 p-3 dark:bg-white/5">
                    <h3 class="mb-2 flex items-center gap-1.5 text-sm font-semibold"><x-ui.icon name="add_circle" :size="18" class="text-brand" />Block a day</h3>
                    @include('owner.schedule._block-form', ['idPrefix' => 'side-block', 'compact' => true])
                </div>
            </section>
        </aside>
    </div>
@endsection
