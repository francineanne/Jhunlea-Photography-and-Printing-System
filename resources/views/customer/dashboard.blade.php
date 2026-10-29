@extends('layouts.customer')

@section('title', 'My dashboard')

{{--
    The customer's home (Phase 5C), after design/customer/customer_dashboard: the greeting, the two things a customer
    can start, a strip of real numbers, what needs their attention and their latest requests. Every number comes from
    DashboardService, which reuses the rules of the lists and the payments ledger.
--}}
@php
    use App\Models\PrintingOrder;
    use App\Support\Money;

    $first = Str::of(auth()->user()->name)->trim()->before(' ')->limit(40, '…');
    $toPay = Money::format(Money::fromCents($data['toPayCents']));
    $tones = ['amber' => 'bg-status-amber-bg text-status-amber', 'blue' => 'bg-status-blue-bg text-status-blue', 'green' => 'bg-status-green-bg text-status-green', 'red' => 'bg-status-red-bg text-status-red'];
@endphp

@section('main')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>My account</p>
            <h1 class="mt-2 break-words font-display text-3xl font-semibold tracking-tight sm:text-4xl">Welcome back, {{ $first }}</h1>
            <p class="mt-2 text-app-text-2">Here is where your printing orders and photoshoots stand.</p>
        </div>
        <p class="flex items-center gap-2 rounded-card border border-app-border bg-app-card px-3 py-2 text-xs shadow-sm dark:shadow-none">
            <x-ui.icon name="storefront" :size="18" class="text-brand" />
            <span><span class="block font-bold uppercase tracking-wider text-app-text-2">Payment reminder</span>Paid face to face at the shop: cash or GCash.</span>
        </p>
    </div>

    {{-- What a customer can start, as the action cards of the design --}}
    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        @foreach ([['route' => 'customer.printing.create', 'icon' => 'print', 'title' => 'Request printing', 'text' => 'Choose a service, add your files and send a request.', 'cta' => 'Upload files'],
            ['route' => 'customer.bookings.create', 'icon' => 'photo_camera', 'title' => 'Book a photoshoot', 'text' => 'Choose a package and a free time, then send a request to the studio.', 'cta' => 'Pick a time']] as $action)
            <a href="{{ route($action['route']) }}" class="group flex items-start gap-4 rounded-card border border-app-border bg-app-card p-5 shadow-sm transition-[border-color,box-shadow,translate] duration-[280ms] ease-brand hover:-translate-y-[3px] hover:border-brand hover:shadow-md focus-ring motion-reduce:hover:translate-y-0 dark:shadow-none">
                <span class="grid size-11 shrink-0 place-items-center rounded-control bg-brand/10 text-brand transition-colors duration-200 group-hover:bg-brand group-hover:text-white" aria-hidden="true"><x-ui.icon :name="$action['icon']" :size="24" /></span>
                <span class="min-w-0">
                    <span class="block font-semibold">{{ $action['title'] }}</span>
                    <span class="mt-0.5 block text-sm text-app-text-2">{{ $action['text'] }}</span>
                    <span class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-brand">{{ $action['cta'] }}<x-ui.icon name="arrow_forward" :size="16" class="transition-transform duration-200 group-hover:translate-x-1" /></span>
                </span>
            </a>
        @endforeach
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-tile label="Active printing orders">
            <x-slot:aside><x-ui.icon name="print" :size="20" class="text-app-text-2" /></x-slot:aside>
            <p class="mt-4 text-3xl font-bold tabular-nums">{{ $data['activeOrders'] }}</p>
            <a href="{{ route('customer.requests.index', ['kind' => 'printing']) }}" class="mt-1 inline-flex min-h-11 items-center gap-1 rounded text-xs font-semibold text-brand hover:underline focus-ring">Not yet completed<x-ui.icon name="arrow_forward" :size="14" /></a>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Upcoming photoshoots" :delay="60">
            <x-slot:aside><x-ui.icon name="photo_camera" :size="20" class="text-app-text-2" /></x-slot:aside>
            <p class="mt-4 text-3xl font-bold tabular-nums">{{ $data['upcomingShoots'] }}</p>
            <a href="{{ route('customer.bookings.index') }}" class="mt-1 inline-flex min-h-11 items-center gap-1 rounded text-xs font-semibold text-brand hover:underline focus-ring">Confirmed or offered times<x-ui.icon name="arrow_forward" :size="14" /></a>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Amount to pay at the shop" :delay="120" glow>
            <x-slot:aside><x-ui.icon name="storefront" :size="20" class="text-brand" /></x-slot:aside>
            <p @class(['mt-4 text-3xl font-bold tabular-nums', 'text-brand' => $data['toPayCents'] > 0])>{{ $toPay }}</p>
            <p class="mt-1 flex min-h-11 items-center text-xs text-app-text-2">Confirmed prices not paid yet, paid face to face</p>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Unread notifications" :delay="180">
            <x-slot:aside><x-ui.icon name="notifications" :size="20" class="text-app-text-2" /></x-slot:aside>
            <p class="mt-4 text-3xl font-bold tabular-nums">{{ $data['unread'] }}</p>
            <a href="{{ route('customer.notifications.index', $data['unread'] > 0 ? ['tab' => 'unread'] : []) }}" class="mt-1 inline-flex min-h-11 items-center gap-1 rounded text-xs font-semibold text-brand hover:underline focus-ring">Open notifications<x-ui.icon name="arrow_forward" :size="14" /></a>
        </x-ui.stat-tile>
    </div>

    @if ($data['requests'] === 0)
        {{-- A brand-new account: the first steps, as the empty state of the design --}}
        <x-ui.card class="mt-8">
            <x-ui.empty-state icon="photo_library" title="No printing orders or photoshoots yet"
                description="Send a printing request or book a photoshoot. Everything you send shows up here with its status and price.">
                <div class="flex flex-wrap justify-center gap-2">
                    <x-ui.button :href="route('customer.bookings.create')"><x-ui.icon name="photo_camera" :size="18" />Book a photoshoot</x-ui.button>
                    <x-ui.button :href="route('customer.printing.create')" variant="outline"><x-ui.icon name="print" :size="18" />Request printing</x-ui.button>
                </div>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="mt-8 grid items-start gap-6 xl:grid-cols-12">
            <section class="min-w-0 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 xl:col-span-5 dark:shadow-none" aria-labelledby="attention-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="attention-title" class="flex items-center gap-2 font-display text-xl font-semibold">
                        @if ($data['attention']->isNotEmpty())<span class="relative flex size-2" aria-hidden="true"><span class="absolute inline-flex size-full animate-ping rounded-full bg-brand opacity-60 motion-reduce:hidden"></span><span class="relative size-2 rounded-full bg-brand"></span></span>@endif
                        Needs your attention
                    </h2>
                    <span class="font-mono text-xs text-app-text-2">{{ $data['attention']->count() }} {{ Str::plural('item', $data['attention']->count()) }}</span>
                </div>
                @if ($data['attention']->isEmpty())
                    <p class="mt-4 flex items-center gap-2 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5"><x-ui.icon name="done_all" :size="18" />Nothing needs your attention right now.</p>
                @else
                    <ul class="mt-4 space-y-2">
                        @foreach ($data['attention'] as $item)
                            <li>
                                <a href="{{ $item['url'] }}" class="group flex items-start gap-3 rounded-control p-3 transition-colors hover:bg-black/[0.03] focus-ring dark:hover:bg-white/5">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl {{ $tones[$item['tone']] }} transition-transform group-hover:scale-95" aria-hidden="true"><x-ui.icon :name="$item['icon']" :size="20" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-baseline gap-x-2"><span class="font-mono text-xs font-bold text-brand">{{ $item['reference'] }}</span><span class="font-semibold">{{ $item['title'] }}</span></span>
                                        <span class="mt-0.5 block text-sm text-app-text-2">{{ $item['text'] }}</span>
                                    </span>
                                    <x-ui.icon name="arrow_forward" :size="18" class="mt-2 shrink-0 text-app-text-2 transition-transform group-hover:translate-x-1" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="min-w-0 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 xl:col-span-7 dark:shadow-none" aria-labelledby="latest-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="latest-title" class="font-display text-xl font-semibold">Latest activity</h2>
                    <a href="{{ route('customer.requests.index') }}" class="inline-flex min-h-11 items-center gap-1 rounded text-sm font-semibold text-brand hover:underline focus-ring">See all requests<x-ui.icon name="arrow_forward" :size="16" /></a>
                </div>
                <ul class="mt-2 divide-y divide-app-border">
                    @foreach ($data['latest'] as $request)
                        @php($isOrder = $request instanceof PrintingOrder)
                        <li>
                            <a href="{{ $isOrder ? route('customer.printing.show', $request) : route('customer.bookings.show', $request) }}" class="group flex items-center gap-3 py-3 focus-ring rounded">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-neutral-100 text-app-text-2 transition-transform group-hover:scale-95 dark:bg-white/10" aria-hidden="true"><x-ui.icon :name="$isOrder ? 'print' : 'photo_camera'" :size="20" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-baseline gap-x-2"><span class="font-mono text-xs font-bold text-brand">{{ $request->reference_number }}</span><span class="text-[11px] font-semibold uppercase tracking-wider text-app-text-2">{{ $isOrder ? 'Printing' : 'Photography' }}</span></span>
                                    <span class="block truncate font-semibold group-hover:text-brand">{{ $isOrder ? $request->serviceName() : ($request->snapshot['package']['name'] ?? $request->package?->name ?? 'Photoshoot') }}</span>
                                    <x-ui.date :value="$request->created_at" class="block font-mono text-[11px] text-app-text-2" />
                                </span>
                                <span class="flex shrink-0 flex-col items-end gap-1"><x-ui.status-badge :status="$request->status" /><x-payment.badge :payable="$request" /></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    @endif
@endsection
