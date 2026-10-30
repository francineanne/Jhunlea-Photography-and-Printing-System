@extends('layouts.owner')

@section('title', 'Reports')

{{--
    The owner's reports (Phase 6A), after design/admin/reports_analytics_atelier_performance: a period filter, then
    requests by status, the most requested services and packages, and payments by method. Every chart is a plain CSS
    bar with a text table beside it. Left out on purpose: export buttons, growth and comparison figures, utilization,
    turnaround, equipment and rack panels, SMS alerts (the system has none of these, and money is only ever recorded
    payments or awaiting payment).
--}}
@php
    use App\Enums\PaymentMethod;
    use App\Support\Money;

    $peso = fn (int $cents) => Money::format(Money::fromCents($cents));
    $share = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100, 1) : 0;
    $recorded = $report['recorded'];
    $statusBlocks = [
        ['id' => 'orders', 'title' => 'Printing orders', 'icon' => 'print', 'data' => $report['orderStatuses']],
        ['id' => 'bookings', 'title' => 'Photography bookings', 'icon' => 'photo_camera', 'data' => $report['bookingStatuses']],
    ];
    $topBlocks = [
        ['id' => 'top-services', 'title' => 'Most requested printing services', 'unit' => 'order', 'rows' => $report['topServices'], 'empty' => 'No printing orders in this period.'],
        ['id' => 'top-packages', 'title' => 'Most requested photography packages', 'unit' => 'booking', 'rows' => $report['topPackages'], 'empty' => 'No photoshoot requests in this period.'],
    ];
@endphp

@section('main')
    <div>
        <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Reports</p>
        <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Shop reports</h1>
        <p class="mt-2 max-w-2xl text-app-text-2">Requests created and payments received in the period you choose. Dates are the shop’s calendar days (Philippine time).</p>
    </div>

    @if ($errors->any())
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </x-ui.notice>
    @endif

    <form method="GET" action="{{ route('owner.reports.index') }}" class="mt-6 rounded-card border border-app-border bg-app-card p-4 dark:shadow-none" aria-label="Report period">
        <nav aria-label="Quick periods">
            <ul class="flex flex-wrap gap-2">
                @foreach ($ranges as $key => $label)
                    @php($current = $period['range'] === $key)
                    <li>
                        <a href="{{ route('owner.reports.index', ['range' => $key]) }}" @if ($current) aria-current="page" @endif
                            @class(['inline-flex min-h-11 items-center whitespace-nowrap rounded-full border px-4 text-sm font-semibold transition-colors focus-ring',
                                'border-brand bg-brand text-white' => $current,
                                'border-app-border text-app-text-2 hover:border-brand hover:text-brand' => ! $current])>{{ $label }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
        <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <x-ui.input label="From" name="from" type="date" :value="$period['range'] === 'custom' ? $period['from'] : null" />
            <x-ui.input label="To" name="to" type="date" :value="$period['range'] === 'custom' ? $period['to'] : null" />
            <x-ui.button type="submit">Show period</x-ui.button>
        </div>
    </form>

    <p class="mt-6 flex flex-wrap items-center gap-2 text-sm">
        <x-ui.icon name="date_range" :size="18" class="text-brand" />
        <span class="font-semibold">{{ $period['label'] }}</span>
        @if ($period['from'] || $period['to'])
            <span class="font-mono text-xs text-app-text-2">
                {{ $period['from'] ? \Illuminate\Support\Carbon::parse($period['from'])->format('M j, Y') : 'The beginning' }} – {{ $period['to'] ? \Illuminate\Support\Carbon::parse($period['to'])->format('M j, Y') : 'today' }}
            </span>
        @endif
    </p>
    <p class="mt-1 text-xs text-app-text-2">Requests are counted on the day they were sent. Payments are counted on the day they were received.</p>

    {{-- Requests by status: every status, zeros included --}}
    <div class="mt-6 grid items-start gap-6 xl:grid-cols-2">
        @foreach ($statusBlocks as $block)
            <section class="min-w-0 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="{{ $block['id'] }}-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="{{ $block['id'] }}-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon :name="$block['icon']" :size="22" class="text-brand" />{{ $block['title'] }}</h2>
                    <span class="font-mono text-xs text-app-text-2">{{ $block['data']['total'] }} in all</span>
                </div>
                <table class="mt-4 w-full text-left text-sm">
                    <caption class="sr-only">{{ $block['title'] }} sent in the period, by status</caption>
                    <thead class="sr-only"><tr><th scope="col">Status</th><th scope="col">Requests</th><th scope="col">Share</th></tr></thead>
                    <tbody>
                        @foreach ($block['data']['rows'] as $row)
                            @php($pct = $share($row['count'], $block['data']['total']))
                            <tr>
                                <th scope="row" class="w-40 py-1.5 pr-3 font-normal sm:w-48">{{ $row['status']->label() }}</th>
                                <td class="min-w-16 py-1.5" aria-hidden="true">
                                    <span class="block h-2.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10">
                                        <span class="block h-full origin-left animate-bar-grow rounded-full bg-brand" style="width: {{ $pct }}%"></span>
                                    </span>
                                </td>
                                <td class="w-24 py-1.5 pl-3 text-right tabular-nums"><span class="font-semibold">{{ $row['count'] }}</span> <span class="text-xs text-app-text-2">({{ $pct }}%)</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endforeach
    </div>

    {{-- Most requested: the name each request was sent with (its snapshot) --}}
    <div class="mt-6 grid items-start gap-6 xl:grid-cols-2">
        @foreach ($topBlocks as $block)
            @php($max = (int) collect($block['rows'])->max('count'))
            <section class="min-w-0 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="{{ $block['id'] }}-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="{{ $block['id'] }}-title" class="font-display text-xl font-semibold">{{ $block['title'] }}</h2>
                    <span class="font-mono text-xs text-app-text-2">Top {{ \App\Services\ReportService::TOP }}</span>
                </div>
                @if (count($block['rows']) === 0)
                    <p class="mt-4 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">{{ $block['empty'] }}</p>
                @else
                    <ol class="mt-4 space-y-3">
                        @foreach ($block['rows'] as $i => $row)
                            <li>
                                <div class="flex items-baseline justify-between gap-3 text-sm">
                                    <span class="flex min-w-0 items-baseline gap-2"><span class="font-mono text-xs text-app-text-2">{{ $i + 1 }}.</span><span class="break-words font-semibold">{{ $row['name'] }}</span></span>
                                    <span class="shrink-0 tabular-nums">{{ $row['count'] }} {{ Str::plural($block['unit'], $row['count']) }}</span>
                                </div>
                                <span class="mt-1.5 block h-2 overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10" aria-hidden="true">
                                    <span class="block h-full origin-left animate-bar-grow rounded-full bg-ink dark:bg-white" style="width: {{ $max > 0 ? round($row['count'] / $max * 100, 1) : 0 }}%"></span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endforeach
    </div>

    {{-- Money: recorded payments in the period, and awaiting payment right now (never added together) --}}
    <div class="mt-6 grid items-start gap-6 xl:grid-cols-2">
        <section class="min-w-0 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="recorded-title">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="recorded-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="account_balance_wallet" :size="22" class="text-brand" />Recorded payments</h2>
                <span class="font-mono text-xs text-app-text-2">{{ $period['label'] }}</span>
            </div>
            <p class="mt-3 text-3xl font-bold tabular-nums">{{ $peso($recorded['cents']) }}</p>
            <p class="mt-1 text-xs text-app-text-2">{{ $recorded['count'] }} {{ Str::plural('payment', $recorded['count']) }} received in the period</p>
            <table class="mt-4 w-full text-left text-sm">
                <caption class="sr-only">Recorded payments in the period, by method</caption>
                <thead class="sr-only"><tr><th scope="col">Method</th><th scope="col">Share</th><th scope="col">Amount</th></tr></thead>
                <tbody>
                    @foreach (PaymentMethod::cases() as $method)
                        @php($row = $recorded['methods'][$method->value])
                        @php($pct = $share($row['cents'], $recorded['cents']))
                        <tr>
                            <th scope="row" class="w-24 py-1.5 pr-3 font-normal">{{ $method->label() }}</th>
                            <td class="w-full min-w-16 py-1.5" aria-hidden="true">
                                <span class="block h-2.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10">
                                    <span class="block h-full origin-left animate-bar-grow rounded-full bg-brand" style="width: {{ $pct }}%"></span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap py-1.5 pl-3 text-right tabular-nums"><span class="font-semibold">{{ $peso($row['cents']) }}</span> <span class="block text-xs text-app-text-2">{{ $row['count'] }} {{ Str::plural('payment', $row['count']) }} · {{ $pct }}%</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-4 rounded-control bg-neutral-100 p-3 text-xs text-app-text-2 dark:bg-white/5">Recorded payments are what the shop actually received. Estimates are never counted.</p>
        </section>

        <section class="min-w-0 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="awaiting-title">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="awaiting-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="pending_actions" :size="22" class="text-status-red" />Awaiting payment right now</h2>
                <span class="font-mono text-xs text-app-text-2">Any date</span>
            </div>
            <p @class(['mt-3 text-3xl font-bold tabular-nums', 'text-status-red' => $report['awaiting']['cents'] > 0])>{{ $peso($report['awaiting']['cents']) }}</p>
            <p class="mt-1 text-xs text-app-text-2">{{ $report['awaiting']['count'] }} confirmed {{ Str::plural('price', $report['awaiting']['count']) }} with no payment recorded yet</p>
            <p class="mt-4 text-sm text-app-text-2">This is not limited to the period above and is not part of the recorded payments. It is what the shop is still waiting to receive today.</p>
            <x-ui.button :href="route('owner.payments.index')" variant="outline" class="mt-4">Open the payments ledger</x-ui.button>
        </section>
    </div>
@endsection
