@extends('layouts.owner')

@section('title', 'Owner overview')

{{--
    The owner overview (Phase 5C), after design/admin/owner_overview: the greeting with the shop's date, a strip of
    real numbers with links, what needs the shop's attention and today's photoshoots in time order. Every number comes
    from DashboardService, which reuses the rules of the lists and the payments ledger. No charts or trends: the system
    only records payments, it does not forecast.
--}}
@php
    use App\Enums\PaymentMethod;
    use App\Support\LocalTime;
    use App\Support\Money;
    use Illuminate\Support\Carbon;

    $peso = fn (int $cents) => Money::format(Money::fromCents($cents));
    $hour = (int) LocalTime::format(Carbon::now('UTC'), 'G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $first = Str::of(auth()->user()->name)->trim()->before(' ')->limit(40, '…');
    $recorded = $data['recordedToday'];
    $tiles = [
        ['label' => 'Waiting for review', 'pill' => 'Printing', 'tone' => 'amber', 'value' => $data['reviewCount'], 'text' => 'Orders submitted or under review', 'url' => route('owner.orders.index', ['status' => 'submitted'])],
        ['label' => 'Photoshoot requests', 'pill' => 'To answer', 'tone' => 'blue', 'value' => $data['requestCount'], 'text' => 'Requests waiting for your answer', 'url' => route('owner.bookings.index', ['status' => 'requested'])],
        ['label' => 'Shoots today', 'pill' => 'Confirmed', 'tone' => 'green', 'value' => $data['shootCount'], 'text' => LocalTime::format(Carbon::now('UTC'), 'D, M j'), 'url' => route('owner.schedule.index', ['view' => 'day', 'date' => $data['today']])],
    ];
    $pills = ['amber' => 'bg-status-amber-bg text-status-amber', 'blue' => 'bg-status-blue-bg text-status-blue', 'green' => 'bg-status-green-bg text-status-green'];
@endphp

@section('main')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Owner overview</p>
            <h1 class="mt-2 break-words font-display text-3xl font-semibold tracking-tight sm:text-4xl">{{ $greeting }}, {{ $first }}</h1>
            <p class="mt-2 text-app-text-2"><x-ui.date :value="Carbon::now('UTC')" format="l, F j, Y" /> · Philippine time</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('owner.orders.index')" variant="outline"><x-ui.icon name="receipt_long" :size="18" />Printing orders</x-ui.button>
            <x-ui.button :href="route('owner.schedule.index')"><x-ui.icon name="calendar_month" :size="18" />Schedule</x-ui.button>
        </div>
    </div>

    <x-ui.notice variant="payment" class="mt-6">
        <p class="text-app-text-2">The system records payments received at the counter (cash or GCash); it never takes payment online.</p>
    </x-ui.notice>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        @foreach ($tiles as $i => $tile)
            <a href="{{ $tile['url'] }}" class="group focus-ring rounded-card">
                <x-ui.stat-tile :label="$tile['label']" :delay="$i * 60" class="h-full transition-[border-color,box-shadow] duration-200 group-hover:border-brand group-hover:shadow-md">
                    <x-slot:aside><span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $pills[$tile['tone']] }}">{{ $tile['pill'] }}</span></x-slot:aside>
                    <p class="mt-4 text-3xl font-bold tabular-nums">{{ $tile['value'] }}</p>
                    <p class="mt-1 flex items-center justify-between gap-2 text-xs text-app-text-2">{{ $tile['text'] }}<x-ui.icon name="arrow_forward" :size="16" class="shrink-0 text-brand transition-transform group-hover:translate-x-0.5" /></p>
                </x-ui.stat-tile>
            </a>
        @endforeach
        <a href="{{ route('owner.payments.index') }}" class="group focus-ring rounded-card">
            <x-ui.stat-tile label="Awaiting payment" :delay="180" class="h-full transition-[border-color,box-shadow] duration-200 group-hover:border-brand group-hover:shadow-md">
                <x-slot:aside><span class="rounded-full bg-status-red-bg px-2 py-0.5 text-[11px] font-bold text-status-red">{{ $data['awaiting']['count'] }} to pay</span></x-slot:aside>
                <p @class(['mt-4 text-2xl font-bold tabular-nums', 'text-status-red' => $data['awaiting']['count'] > 0])>{{ $peso($data['awaiting']['cents']) }}</p>
                <p class="mt-1 flex items-center justify-between gap-2 text-xs text-app-text-2">Confirmed prices not yet paid<x-ui.icon name="arrow_forward" :size="16" class="shrink-0 text-brand transition-transform group-hover:translate-x-0.5" /></p>
            </x-ui.stat-tile>
        </a>
        <a href="{{ route('owner.payments.index', ['from' => $data['today'], 'to' => $data['today']]) }}" class="group focus-ring rounded-card">
            <x-ui.stat-tile label="Recorded payments today" :delay="240" glow class="h-full transition-[border-color,box-shadow] duration-200 group-hover:border-brand group-hover:shadow-md">
                <x-slot:aside><x-ui.icon name="point_of_sale" :size="20" class="text-brand" /></x-slot:aside>
                <p class="mt-4 text-2xl font-bold tabular-nums">{{ $peso($recorded['cents']) }}</p>
                <p class="mt-1 text-xs text-app-text-2">Cash {{ $peso($recorded['methods'][PaymentMethod::Cash->value]['cents']) }} · GCash {{ $peso($recorded['methods'][PaymentMethod::Gcash->value]['cents']) }}</p>
            </x-ui.stat-tile>
        </a>
        <a href="{{ route('owner.notifications.index', $data['unread'] > 0 ? ['tab' => 'unread'] : []) }}" class="group focus-ring rounded-card">
            <x-ui.stat-tile label="Unread notifications" :delay="300" class="h-full transition-[border-color,box-shadow] duration-200 group-hover:border-brand group-hover:shadow-md">
                <x-slot:aside><x-ui.icon name="notifications" :size="20" class="text-app-text-2" /></x-slot:aside>
                <p class="mt-4 text-3xl font-bold tabular-nums">{{ $data['unread'] }}</p>
                <p class="mt-1 flex items-center justify-between gap-2 text-xs text-app-text-2">What customers did<x-ui.icon name="arrow_forward" :size="16" class="shrink-0 text-brand transition-transform group-hover:translate-x-0.5" /></p>
            </x-ui.stat-tile>
        </a>
    </div>

    <div class="mt-8 grid items-start gap-6 xl:grid-cols-12">
        <section class="min-w-0 space-y-6 xl:col-span-7" aria-labelledby="attention-title">
            <h2 id="attention-title" class="flex items-center gap-2 font-display text-2xl font-semibold">
                <span class="size-2 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Needs attention
            </h2>

            @foreach ([
                ['title' => 'Printing orders waiting for review', 'empty' => 'No printing orders are waiting for review.', 'rows' => $data['review'], 'count' => $data['reviewCount'], 'all' => route('owner.orders.index', ['status' => 'submitted']), 'kind' => 'order'],
                ['title' => 'Photoshoot requests to answer', 'empty' => 'No photoshoot requests are waiting.', 'rows' => $data['requests'], 'count' => $data['requestCount'], 'all' => route('owner.bookings.index', ['status' => 'requested']), 'kind' => 'booking'],
            ] as $list)
                <div class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="font-semibold">{{ $list['title'] }} <span class="ml-1 rounded-full bg-black/5 px-2 py-0.5 font-mono text-xs dark:bg-white/10">{{ $list['count'] }}</span></h3>
                        @if ($list['count'] > 0)
                            <a href="{{ $list['all'] }}" class="inline-flex min-h-11 items-center gap-1 rounded text-sm font-semibold text-brand hover:underline focus-ring">See all<x-ui.icon name="arrow_forward" :size="16" /></a>
                        @endif
                    </div>
                    @if ($list['rows']->isEmpty())
                        <p class="mt-3 flex items-center gap-2 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5"><x-ui.icon name="done_all" :size="18" />{{ $list['empty'] }}</p>
                    @else
                        <ul class="mt-3 space-y-2">
                            @foreach ($list['rows'] as $row)
                                <li>
                                    <a href="{{ $list['kind'] === 'order' ? route('owner.orders.show', $row) : route('owner.bookings.show', $row) }}" class="group flex items-center gap-3 rounded-control bg-neutral-100/70 p-3 transition-colors hover:bg-neutral-100 focus-ring dark:bg-white/5 dark:hover:bg-white/10">
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-baseline gap-x-2"><span class="font-mono text-sm font-bold text-brand">{{ $row->reference_number }}</span><span class="truncate font-semibold">{{ $row->customer?->name ?? 'Deleted account' }}</span></span>
                                            <span class="mt-0.5 block truncate text-xs text-app-text-2">
                                                {{ $list['kind'] === 'order' ? $row->serviceName() : ($row->snapshot['package']['name'] ?? $row->package?->name) }} ·
                                                @if ($list['kind'] === 'order') sent <x-ui.date :value="$row->created_at" /> @else asks for <x-ui.date :value="$row->starts_at" /> @endif
                                            </span>
                                        </span>
                                        <x-ui.status-badge :status="$row->status" />
                                        <x-ui.icon name="arrow_forward" :size="18" class="shrink-0 text-app-text-2 transition-transform group-hover:translate-x-0.5" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach

            <div class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-semibold">Awaiting payment <span class="ml-1 rounded-full bg-black/5 px-2 py-0.5 font-mono text-xs dark:bg-white/10">{{ $data['awaiting']['count'] }}</span></h3>
                    <a href="{{ route('owner.payments.index') }}" class="inline-flex min-h-11 items-center gap-1 rounded text-sm font-semibold text-brand hover:underline focus-ring">Payments ledger<x-ui.icon name="arrow_forward" :size="16" /></a>
                </div>
                @php($preview = $data['awaiting']['bookings']->take(3)->concat($data['awaiting']['orders']->take(3)))
                @if ($preview->isEmpty())
                    <p class="mt-3 flex items-center gap-2 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5"><x-ui.icon name="done_all" :size="18" />Nothing is waiting for payment.</p>
                @else
                    <ul class="mt-3 divide-y divide-app-border">
                        @foreach ($preview as $payable)
                            @php($isOrder = $payable instanceof \App\Models\PrintingOrder)
                            <li>
                                <a href="{{ ($isOrder ? route('owner.orders.show', $payable) : route('owner.bookings.show', $payable)).'#payment' }}" class="flex min-h-11 items-center justify-between gap-3 rounded py-2.5 hover:text-brand focus-ring">
                                    <span class="min-w-0"><span class="block font-mono text-sm font-semibold">{{ $payable->reference_number }} <span class="font-sans text-xs font-normal text-app-text-2">· {{ $isOrder ? $payable->status->label() : 'Photoshoot' }}</span></span><span class="block truncate text-xs text-app-text-2">{{ $payable->customer?->name ?? 'Deleted account' }}</span></span>
                                    <span class="shrink-0 font-semibold tabular-nums">{{ Money::format($payable->confirmed_price) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        <div class="min-w-0 space-y-6 xl:col-span-5">
            <section class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="today-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="today-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="photo_camera" :size="22" class="text-brand" />Today’s photoshoots</h2>
                    <x-ui.date :value="Carbon::now('UTC')" format="M j, Y" class="font-mono text-xs text-app-text-2" />
                </div>
                @if ($data['shoots']->isEmpty())
                    <p class="mt-4 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">No confirmed photoshoots today.</p>
                @else
                    {{-- A timeline, as in the design: a line with a marker per session --}}
                    <ol class="relative mt-5 space-y-4 pl-6 before:absolute before:bottom-2 before:left-[5px] before:top-2 before:w-px before:bg-app-border before:content-['']">
                        @foreach ($data['shoots'] as $shoot)
                            <li class="relative">
                                <span class="absolute -left-6 top-1.5 size-3 rounded-full border-2 border-app-card bg-brand" aria-hidden="true"></span>
                                <a href="{{ route('owner.bookings.show', $shoot) }}" class="block rounded-control bg-neutral-100/70 p-3 transition-colors hover:bg-neutral-100 focus-ring dark:bg-white/5 dark:hover:bg-white/10">
                                    <span class="font-mono text-sm font-bold"><x-ui.date :value="$shoot->starts_at" format="g:i A" /> – <x-ui.date :value="$shoot->ends_at" format="g:i A" /></span>
                                    <span class="mt-1 block truncate font-semibold">{{ $shoot->snapshot['package']['name'] ?? $shoot->package?->name }}</span>
                                    <span class="block truncate text-xs text-app-text-2">{{ $shoot->reference_number }} · {{ $shoot->customer?->name ?? 'Deleted account' }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                    @if ($data['shootCount'] > $data['shoots']->count())
                        <a href="{{ route('owner.schedule.index', ['view' => 'day', 'date' => $data['today']]) }}" class="mt-3 inline-flex min-h-11 items-center gap-1 rounded text-sm font-semibold text-brand hover:underline focus-ring">All {{ $data['shootCount'] }} on the calendar<x-ui.icon name="arrow_forward" :size="16" /></a>
                    @endif
                @endif
            </section>

            <nav aria-label="Shop pages" class="rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none">
                <h2 class="font-display text-xl font-semibold">Go to</h2>
                <ul class="mt-3 grid grid-cols-2 gap-2">
                    @foreach ([['owner.orders.index', 'receipt_long', 'Printing orders'], ['owner.bookings.index', 'photo_camera_front', 'Bookings'], ['owner.schedule.index', 'calendar_month', 'Schedule'], ['owner.payments.index', 'point_of_sale', 'Payments'], ['owner.services.index', 'print', 'Services'], ['owner.packages.index', 'photo_camera', 'Packages']] as [$route, $icon, $label])
                        <li><a href="{{ route($route) }}" class="flex min-h-11 items-center gap-2 rounded-control bg-neutral-100/70 px-3 text-sm font-semibold transition-colors hover:bg-neutral-100 hover:text-brand focus-ring dark:bg-white/5 dark:hover:bg-white/10"><x-ui.icon :name="$icon" :size="18" />{{ $label }}</a></li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </div>
@endsection
