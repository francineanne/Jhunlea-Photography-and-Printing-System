@extends('layouts.owner')

@section('title', 'Income report')

{{--
    The owner's income report (Phase 8C), after design/admin/reports_analytics_atelier_performance: the header with the
    period controls and the export action, a row of stat tiles, income over time beside the payment methods, the top
    services and packages beside where the income came from, and the payments behind the numbers. Every figure comes
    from IncomeReportService (the Payments ledger's own rules). Left out on purpose: equipment, bay utilization,
    turnaround, SLA and status-lifecycle panels, the receivables rack with SMS alerts, Maya and POS methods, and any
    revenue, sales or profit wording (the system has none of these).
--}}
@php
    use App\Models\PrintingOrder;
    use App\Support\LocalTime;
    use App\Support\Money;
    use Illuminate\Support\Carbon;

    $r = $report;
    $cmp = $r['comparison'];
    $today = LocalTime::in(Carbon::now('UTC'));
    $hasData = $r['count'] > 0 || $r['voided']['count'] > 0;
    $here = fn (array $query) => route('owner.reports.income', $query);
    $presets = match ($period->view) {
        'daily' => ['Today' => $today->format('Y-m-d'), 'Yesterday' => $today->copy()->subDay()->format('Y-m-d')],
        'weekly' => ['This week' => $today->format('Y-m-d')],
        'monthly' => ['This month' => $today->format('Y-m-d')],
        default => [],
    };
    $bucketWords = ['hour' => 'per hour', 'day' => 'per day', 'month' => 'per month'][$period->bucket];
    $cmpLine = \App\Services\Reports\IncomeReportService::comparisonText($r);
    $cmpIcon = $cmp === null ? null : ['up' => 'trending_up', 'down' => 'trending_down', 'same' => 'trending_flat'][$cmp['direction']];
    $itemMax = max(1, (int) collect($r['items']['rows'])->max('cents'));
    $payableUrl = fn ($payable) => $payable instanceof PrintingOrder ? route('owner.orders.show', $payable) : route('owner.bookings.show', $payable);
    $csvQuery = array_filter(['view' => $period->view, 'date' => in_array($period->view, ['daily', 'weekly', 'monthly'], true) ? $period->date : null, 'from' => $period->view === 'custom' ? $period->from : null, 'to' => $period->view === 'custom' ? $period->to : null]);
@endphp

@section('main')
<div id="income-report">
    {{-- Header: title on the left; views, period and actions on the right (the design's header) --}}
    <div class="flex flex-wrap items-end justify-between gap-6">
        <div class="min-w-0 xl:max-w-xl">
            <span class="inline-flex items-center gap-2 rounded-full bg-brand/10 px-3 py-1 font-mono text-[11px] font-semibold uppercase tracking-wider text-brand"><span class="size-1.5 rounded-full bg-brand" aria-hidden="true"></span>Income report</span>
            <h1 class="mt-3 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Income and payments</h1>
            <p class="mt-2 text-app-text-2">What the shop received, from recorded payments only. Voided payments and unpaid prices never count. Shop calendar days (Philippine time); weeks start on Monday.</p>
        </div>
        <div class="flex flex-wrap gap-2 print:hidden">
            @if ($period->view === 'daily')
                <x-ui.button variant="outline" :href="route('owner.reports.end-of-day', ['date' => $period->date])"><x-ui.icon name="summarize" :size="18" />End-of-day summary</x-ui.button>
            @endif
            <x-ui.button variant="outline" type="button" onclick="window.print()"><x-ui.icon name="print" :size="18" />Print report</x-ui.button>
            <x-ui.button :href="route('owner.reports.csv', $csvQuery)"><x-ui.icon name="download" :size="18" />Export CSV</x-ui.button>
        </div>
    </div>

    @include('owner.reports._tabs')

    @if ($errors->any())
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg print:hidden">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </x-ui.notice>
    @endif

    {{-- Period controls: the view, then previous / the period / next, presets and a date picker --}}
    <section class="mt-6 rounded-card border border-app-border bg-app-card p-4 shadow-sm dark:shadow-none print:hidden" aria-label="Report period">
        <nav aria-label="Views" class="flex flex-wrap gap-1 rounded-control bg-neutral-100 p-1 sm:inline-flex dark:bg-white/5">
            @foreach ($views as $key => $label)
                @php($current = $period->view === $key)
                <a href="{{ $here(['view' => $key]) }}" @if ($current) aria-current="page" @endif
                    @class(['inline-flex min-h-11 flex-1 items-center justify-center whitespace-nowrap rounded-[8px] px-4 text-sm font-semibold transition-colors focus-ring sm:flex-none',
                        'bg-app-card text-app-text shadow-sm dark:bg-white/15' => $current,
                        'text-app-text-2 hover:text-app-text' => ! $current])>{{ $label }}</a>
            @endforeach
        </nav>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            @if ($period->prevDate || $period->nextDate || in_array($period->view, ['daily', 'weekly', 'monthly'], true))
                <div class="flex min-w-0 items-center gap-1 rounded-control border border-app-border">
                    <a href="{{ $here(['view' => $period->view, 'date' => $period->prevDate]) }}" class="grid size-11 shrink-0 place-items-center rounded-control text-app-text-2 hover:bg-black/5 hover:text-app-text focus-ring dark:hover:bg-white/10" aria-label="Previous {{ ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'][$period->view] }}"><x-ui.icon name="chevron_left" /></a>
                    <span class="flex min-w-0 items-center gap-2 px-2 text-sm font-semibold"><x-ui.icon name="calendar_month" :size="18" class="shrink-0 text-brand" /><span class="min-w-0 break-words">{{ $period->label }}</span></span>
                    @if ($period->nextDate)
                        <a href="{{ $here(['view' => $period->view, 'date' => $period->nextDate]) }}" class="grid size-11 shrink-0 place-items-center rounded-control text-app-text-2 hover:bg-black/5 hover:text-app-text focus-ring dark:hover:bg-white/10" aria-label="Next {{ ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'][$period->view] }}"><x-ui.icon name="chevron_right" /></a>
                    @else
                        <span class="grid size-11 shrink-0 place-items-center text-app-muted" title="This is the current {{ ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'][$period->view] }}"><x-ui.icon name="chevron_right" /><span class="sr-only">No next {{ ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'][$period->view] }}: this is the current one</span></span>
                    @endif
                </div>
                @foreach ($presets as $label => $date)
                    @php($on = $period->view === 'daily' ? $period->date === $date : $period->isCurrent)
                    <a href="{{ $here(['view' => $period->view, 'date' => $date]) }}" @class(['inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-semibold transition-colors focus-ring',
                        'border-brand bg-brand text-white' => $on, 'border-app-border text-app-text-2 hover:border-brand hover:text-brand' => ! $on])>{{ $label }}</a>
                @endforeach
                <form method="GET" action="{{ route('owner.reports.income') }}" class="flex items-end gap-2 sm:ml-auto">
                    <input type="hidden" name="view" value="{{ $period->view }}">
                    <x-ui.input :label="['daily' => 'Pick a day', 'weekly' => 'A day in the week', 'monthly' => 'A day in the month'][$period->view]" name="date" type="date" :value="$period->date" :max="$today->format('Y-m-d')" />
                    <x-ui.button type="submit" variant="outline">Show</x-ui.button>
                </form>
            @elseif ($period->view === 'custom' || $period->view === 'all')
                <form method="GET" action="{{ route('owner.reports.income') }}" class="grid w-full gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                    <x-ui.input label="From" name="from" type="date" :value="$period->from" />
                    <x-ui.input label="To" name="to" type="date" :value="$period->to" />
                    <x-ui.button type="submit">Show period</x-ui.button>
                </form>
                <p class="flex items-center gap-2 text-sm font-semibold"><x-ui.icon name="calendar_month" :size="18" class="text-brand" />{{ $period->label }}</p>
            @endif
        </div>
    </section>

    {{-- On paper the controls are hidden; the period stays readable --}}
    <p class="mt-4 hidden text-sm font-semibold print:block">Period: {{ $period->label }}</p>

    {{-- Stat tiles --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-ui.stat-tile label="Income" glow>
            <x-slot:aside><x-ui.icon name="account_balance_wallet" :size="20" class="text-brand" /></x-slot:aside>
            <div class="mt-4">
                <p class="text-3xl font-bold tabular-nums">{{ Money::cents($r['cents']) }}</p>
                @if ($cmpLine)
                    <p class="mt-2 flex items-start gap-1.5 text-xs font-semibold {{ $cmp['direction'] === 'up' && $cmp['cents'] > 0 ? 'text-status-green' : ($cmp['direction'] === 'down' ? 'text-status-red' : 'text-app-text-2') }}">
                        <x-ui.icon :name="$cmpIcon" :size="16" class="shrink-0" /><span>{{ $cmpLine }}</span>
                    </p>
                @else
                    <p class="mt-2 text-xs text-app-text-2">Every recorded payment so far.</p>
                @endif
            </div>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Payments" :delay="60">
            <x-slot:aside><x-ui.icon name="receipt_long" :size="20" class="text-app-text-2" /></x-slot:aside>
            <div class="mt-4">
                <p class="text-3xl font-bold tabular-nums">{{ $r['count'] }}</p>
                <p class="mt-2 text-xs text-app-text-2">Recorded {{ Str::plural('payment', $r['count']) }}{{ $r['voided']['count'] > 0 ? ' · '.$r['voided']['count'].' voided, not counted' : '' }}</p>
            </div>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Average per payment" :delay="120">
            <x-slot:aside><x-ui.icon name="functions" :size="20" class="text-app-text-2" /></x-slot:aside>
            <div class="mt-4">
                <p class="text-3xl font-bold tabular-nums">{{ Money::cents($r['average']) }}</p>
                <p class="mt-2 text-xs text-app-text-2">Income divided by recorded payments</p>
            </div>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Cash" :delay="180">
            <x-slot:aside><x-ui.icon name="payments" :size="20" class="text-app-text-2" /></x-slot:aside>
            <div class="mt-4">
                <p class="text-3xl font-bold tabular-nums">{{ Money::cents($r['methods']['cash']['cents']) }}</p>
                <p class="mt-2 text-xs text-app-text-2">{{ $r['methods']['cash']['count'] }} cash {{ Str::plural('payment', $r['methods']['cash']['count']) }}</p>
            </div>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="GCash" :delay="240">
            <x-slot:aside><x-ui.icon name="qr_code_2" :size="20" class="text-app-text-2" /></x-slot:aside>
            <div class="mt-4">
                <p class="text-3xl font-bold tabular-nums">{{ Money::cents($r['methods']['gcash']['cents']) }}</p>
                <p class="mt-2 text-xs text-app-text-2">{{ $r['methods']['gcash']['count'] }} GCash {{ Str::plural('payment', $r['methods']['gcash']['count']) }}</p>
            </div>
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Still awaiting payment" :delay="300">
            <x-slot:aside><x-ui.icon name="pending_actions" :size="20" class="text-status-red" /></x-slot:aside>
            <div class="mt-4">
                <p @class(['text-3xl font-bold tabular-nums', 'text-status-red' => $r['awaiting']['cents'] > 0])>{{ Money::cents($r['awaiting']['cents']) }}</p>
                <p class="mt-2 text-xs text-app-text-2">{{ $r['awaiting']['count'] }} confirmed {{ Str::plural('price', $r['awaiting']['count']) }} not paid yet · right now, any date · not income</p>
            </div>
        </x-ui.stat-tile>
    </div>

    @if (! $hasData)
        <x-ui.card class="mt-6">
            <x-ui.empty-state icon="savings" title="No payments in this period" description="Nothing was recorded for {{ $period->label }}. Payments recorded at the counter appear here on the day they are received.">
                <x-ui.button :href="route('owner.payments.index')" variant="outline">Open Payments and counter</x-ui.button>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        {{-- Income over time, beside the payment methods --}}
        <div class="mt-6 grid items-start gap-6 lg:grid-cols-12">
            <section class="min-w-0 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 lg:col-span-7 dark:shadow-none" aria-labelledby="over-time-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="over-time-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="bar_chart" :size="22" class="text-brand" />Income over time</h2>
                    <span class="rounded-full bg-neutral-100 px-3 py-1 font-mono text-[11px] text-app-text-2 dark:bg-white/5">{{ ucfirst($bucketWords) }}</span>
                </div>
                <p class="mt-1 text-xs text-app-text-2">Recorded payments {{ $bucketWords }}, by the time they were received.</p>
                <x-report.bars class="mt-5" :series="$r['series']" caption="Income {{ $bucketWords }}, {{ $period->label }}" :unit="['hour' => 'Hour', 'day' => 'Day', 'month' => 'Month'][$period->bucket]" />
            </section>

            <section class="min-w-0 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 lg:col-span-5 dark:shadow-none" aria-labelledby="methods-title">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 id="methods-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="account_balance" :size="22" class="text-brand" />Payment methods</h2>
                    <p class="text-right"><span class="block text-[10px] font-bold uppercase tracking-wider text-app-text-2">Income</span><span class="text-xl font-bold tabular-nums text-brand">{{ Money::cents($r['cents']) }}</span></p>
                </div>
                <p class="mt-1 text-xs text-app-text-2">Paid face to face at the shop, in cash or by GCash.</p>
                <x-report.split class="mt-5" caption="Income by payment method" :parts="[
                    ['label' => 'Cash', 'icon' => 'payments', 'cents' => $r['methods']['cash']['cents'], 'count' => $r['methods']['cash']['count']],
                    ['label' => 'GCash', 'icon' => 'qr_code_2', 'cents' => $r['methods']['gcash']['cents'], 'count' => $r['methods']['gcash']['count']],
                ]" />
                <p class="mt-4 flex items-start gap-2 text-xs text-app-text-2"><x-ui.icon name="info" :size="16" class="mt-px shrink-0" />Cash expected in the drawer for this period is the Cash total above.</p>
            </section>
        </div>

        {{-- Top services and packages, beside where the income came from --}}
        <div class="mt-6 grid items-start gap-6 lg:grid-cols-12">
            <section class="min-w-0 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 lg:col-span-7 dark:shadow-none" aria-labelledby="items-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="items-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="military_tech" :size="22" class="text-brand" />Top services and packages</h2>
                    <span class="font-mono text-[11px] uppercase tracking-wider text-app-text-2">Ranked by income</span>
                </div>
                @if (count($r['items']['rows']) === 0)
                    <p class="mt-4 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">No recorded payments in this period.</p>
                @else
                    <ol class="mt-4 space-y-2">
                        @foreach ($r['items']['rows'] as $i => $item)
                            <li class="rounded-control border border-app-border bg-app-bg/60 p-3">
                                <div class="flex items-center gap-3">
                                    <span @class(['grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold', 'bg-brand text-white' => $i === 0, 'bg-neutral-100 text-app-text-2 dark:bg-white/10' => $i > 0])>{{ $i + 1 }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="break-words text-sm font-semibold">{{ $item['name'] }}</p>
                                        <p class="text-xs text-app-text-2">{{ $item['kind'] === 'printing_order' ? 'Printing' : 'Photography' }} · {{ $item['count'] }} {{ Str::plural('payment', $item['count']) }} · {{ $r['cents'] > 0 ? round($item['cents'] / $r['cents'] * 100, 1) : 0 }}% of income</p>
                                    </div>
                                    <span class="shrink-0 font-bold tabular-nums text-brand">{{ Money::cents($item['cents']) }}</span>
                                </div>
                                <span class="mt-2 block h-1.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10" aria-hidden="true">
                                    <span class="{{ $item['kind'] === 'printing_order' ? 'chart-a' : 'chart-b' }} block h-full origin-left animate-bar-grow rounded-full" style="width: {{ round($item['cents'] / $itemMax * 100, 1) }}%"></span>
                                </span>
                            </li>
                        @endforeach
                        @if ($r['items']['other']['items'] > 0)
                            <li class="flex items-center justify-between gap-3 rounded-control border border-dashed border-app-border p-3 text-sm">
                                <span>Other ({{ $r['items']['other']['items'] }} more) · {{ $r['items']['other']['count'] }} {{ Str::plural('payment', $r['items']['other']['count']) }}</span>
                                <span class="font-bold tabular-nums">{{ Money::cents($r['items']['other']['cents']) }}</span>
                            </li>
                        @endif
                    </ol>
                @endif
            </section>

            <section class="min-w-0 space-y-6 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 lg:col-span-5 dark:shadow-none" aria-labelledby="sources-title">
                <div>
                    <h2 id="sources-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="storefront" :size="22" class="text-brand" />Where it came from</h2>
                    <p class="mt-1 text-xs text-app-text-2">By the paid order or booking: sent online or made at the counter, and printing or photography.</p>
                </div>
                <x-report.split caption="Income by source" :parts="[
                    ['label' => 'Online', 'icon' => 'language', 'cents' => $r['sources']['online']['cents'], 'count' => $r['sources']['online']['count']],
                    ['label' => 'Walk-in', 'icon' => 'storefront', 'cents' => $r['sources']['walk_in']['cents'], 'count' => $r['sources']['walk_in']['count']],
                ]" />
                <x-report.split caption="Income by kind" :parts="[
                    ['label' => 'Printing', 'icon' => 'print', 'cents' => $r['kinds']['printing_order']['cents'], 'count' => $r['kinds']['printing_order']['count']],
                    ['label' => 'Photography', 'icon' => 'photo_camera', 'cents' => $r['kinds']['photography_booking']['cents'], 'count' => $r['kinds']['photography_booking']['count']],
                ]" />
            </section>
        </div>

        {{-- The payments behind the numbers --}}
        <section class="mt-6 min-w-0 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="payments-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="payments-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="receipt" :size="22" class="text-brand" />Payments behind the numbers</h2>
                    <p class="mt-1 text-xs text-app-text-2">{{ $payments->total() }} {{ Str::plural('record', $payments->total()) }} · newest first · voided ones are struck through and not counted</p>
                </div>
                <x-ui.button variant="outline" size="sm" :href="route('owner.payments.index', $period->ledgerQuery())" class="print:hidden"><x-ui.icon name="open_in_new" :size="18" />Open in Payments ledger</x-ui.button>
            </div>

            <div class="mt-4 hidden relative overflow-x-auto rounded-control border border-app-border lg:block">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Payments in the period, newest first</caption>
                    <thead class="bg-neutral-50 text-[11px] uppercase tracking-wider text-app-text-2 dark:bg-white/5">
                        <tr>
                            <th scope="col" class="px-3 py-3 font-bold">Payment</th>
                            <th scope="col" class="px-3 py-3 font-bold">Received</th>
                            <th scope="col" class="px-3 py-3 font-bold">Customer</th>
                            <th scope="col" class="px-3 py-3 font-bold">For</th>
                            <th scope="col" class="px-3 py-3 font-bold">Method</th>
                            <th scope="col" class="px-3 py-3 text-right font-bold">Amount</th>
                            <th scope="col" class="px-3 py-3 font-bold">Recorded by</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-app-border">
                        @foreach ($payments as $payment)
                            @php($ok = $payment->isRecorded())
                            <tr @class(['opacity-70' => ! $ok])>
                                <td class="px-3 py-3">
                                    <p @class(['font-mono text-sm font-bold text-brand', 'line-through' => ! $ok])>{{ $payment->reference_number }}</p>
                                    <p class="mt-1 flex flex-wrap items-center gap-2">
                                        <x-ui.badge :tone="$ok ? 'green' : 'gray'">{{ $ok ? 'Recorded' : 'Voided · not counted' }}</x-ui.badge>
                                        <a href="{{ route('owner.payments.receipt', $payment) }}" class="inline-flex min-h-9 items-center gap-1 rounded text-xs font-semibold text-app-text-2 hover:text-brand focus-ring print:hidden"><x-ui.icon name="print" :size="14" />Receipt</a>
                                    </p>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 font-mono text-xs"><x-ui.date :value="$payment->received_at" /></td>
                                <td class="max-w-44 px-3 py-3"><p class="truncate font-semibold" title="{{ $payment->payable?->customer?->name }}">{{ $payment->payable?->customer?->name ?? 'Deleted account' }}</p></td>
                                <td class="px-3 py-3">
                                    @if ($payment->payable)
                                        <a href="{{ $payableUrl($payment->payable) }}" class="inline-flex min-h-11 items-center rounded font-mono text-sm font-semibold hover:text-brand hover:underline focus-ring sm:min-h-0">{{ $payment->payable->reference_number }}</a>
                                        <p class="flex flex-wrap items-center gap-1.5 text-xs text-app-text-2">{{ $payment->payable instanceof PrintingOrder ? 'Printing order' : 'Photoshoot' }}
                                            @if ($payment->payable->isWalkIn())<x-walk-in.badge :record="$payment->payable" />@else<span class="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-semibold dark:bg-white/10">Online</span>@endif</p>
                                    @else
                                        <span class="text-app-text-2">Removed record</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3"><span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-semibold dark:bg-white/10"><x-ui.icon :name="$payment->method->icon()" :size="14" />{{ $payment->method->label() }}</span></td>
                                <td @class(['whitespace-nowrap px-3 py-3 text-right font-bold tabular-nums', 'line-through' => ! $ok])>{{ Money::format($payment->amount) }}</td>
                                <td class="max-w-36 px-3 py-3 text-xs"><p class="truncate">{{ $payment->receiver?->name ?? 'Deleted account' }}</p></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Phones and tablets: one card per payment --}}
            <ul class="mt-4 space-y-3 lg:hidden">
                @foreach ($payments as $payment)
                    @php($ok = $payment->isRecorded())
                    <li @class(['rounded-control border border-app-border p-3', 'opacity-75' => ! $ok])>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span @class(['font-mono text-sm font-bold text-brand', 'line-through' => ! $ok])>{{ $payment->reference_number }}</span>
                            <span @class(['font-bold tabular-nums', 'line-through' => ! $ok])>{{ Money::format($payment->amount) }}</span>
                        </div>
                        <p class="mt-1 break-words text-sm font-semibold">{{ $payment->payable?->customer?->name ?? 'Deleted account' }}</p>
                        <p class="mt-1 text-xs text-app-text-2"><x-ui.date :value="$payment->received_at" /> · {{ $payment->method->label() }} · {{ $payment->receiver?->name ?? 'Deleted account' }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <x-ui.badge :tone="$ok ? 'green' : 'gray'">{{ $ok ? 'Recorded' : 'Voided · not counted' }}</x-ui.badge>
                            @if ($payment->payable)
                                <a href="{{ $payableUrl($payment->payable) }}" class="inline-flex min-h-11 items-center rounded font-mono text-sm font-semibold hover:text-brand focus-ring">{{ $payment->payable->reference_number }}</a>
                                @if ($payment->payable->isWalkIn())<x-walk-in.badge :record="$payment->payable" />@else<span class="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-semibold dark:bg-white/10">Online</span>@endif
                            @endif
                            <a href="{{ route('owner.payments.receipt', $payment) }}" class="inline-flex min-h-11 items-center gap-1 rounded text-sm font-semibold text-app-text-2 hover:text-brand focus-ring print:hidden"><x-ui.icon name="print" :size="16" />Receipt</a>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4 print:hidden">{{ $payments->links('pagination.simple') }}</div>

            <div class="mt-4 rounded-control bg-neutral-100 p-3 text-sm dark:bg-white/5">
                <p><span class="font-semibold">Recorded in this period: {{ Money::cents($r['cents']) }}</span> from {{ $r['count'] }} {{ Str::plural('payment', $r['count']) }}. Report total and ledger total are the same for these dates.</p>
                @if ($r['voided']['count'] > 0)
                    <p class="mt-1 text-app-text-2">{{ $r['voided']['count'] }} voided {{ Str::plural('payment', $r['voided']['count']) }} ({{ Money::cents($r['voided']['cents']) }}) {{ $r['voided']['count'] === 1 ? 'is' : 'are' }} listed but not counted.</p>
                @endif
            </div>
        </section>
    @endif

    <p class="mt-6 hidden text-xs print:block">Prepared from the system’s payment records. Not an official document.</p>
</div>
@endsection
