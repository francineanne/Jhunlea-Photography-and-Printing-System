{{--
    The end-of-day summary of one shop day (Phase 8C), printable on a normal A4 printer like the 8B payment receipt:
    a page of its own, inline CSS, system fonts, black on white, no navigation, no dark mode, nothing loaded from
    outside. Income is the day's recorded payments; voided payments are listed apart and never counted; "still
    awaiting payment" is right now and never income. No cash count, variance or closing: the shop has not decided yet.
--}}
@php
    use App\Models\PrintingOrder;
    use App\Support\LocalTime;
    use App\Support\Money;
    use Illuminate\Support\Carbon;

    $r = $report;
    $shopName = $shop['shop_name'] ?: config('app.name');
    $what = fn ($payable) => $payable === null ? 'Removed record' : ($payable instanceof PrintingOrder ? 'Printing order' : 'Photoshoot');
    $date = Carbon::parse($period->date);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>End-of-day summary {{ $period->date }}</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #f5f5f5; color: #111; color-scheme: light; }
        body { font: 13px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; padding: 24px 16px; }
        .bar { max-width: 190mm; margin: 0 auto 16px; display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; align-items: center; }
        .bar a, .bar button { min-height: 44px; padding: 0 18px; border-radius: 10px; font: 600 14px/44px system-ui, sans-serif; text-decoration: none; cursor: pointer; }
        .bar a { color: #111; border: 1px solid #d4d4d4; background: #fff; }
        .bar button { color: #fff; background: #c62828; border: 0; }
        .bar a:focus-visible, .bar button:focus-visible { outline: 3px solid #c62828; outline-offset: 2px; }
        .sheet { max-width: 190mm; margin: 0 auto; background: #fff; border: 1px solid #d4d4d4; border-radius: 12px; padding: 24px; }
        header { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 24px; border-bottom: 2px solid #111; padding-bottom: 10px; }
        h1 { margin: 0; font-size: 18px; }
        h2 { margin: 18px 0 6px; font-size: 13px; text-transform: uppercase; letter-spacing: .06em; }
        .muted { color: #404040; }
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; margin-top: 14px; }
        .tile { border: 1px solid #a3a3a3; border-radius: 8px; padding: 8px 10px; }
        .tile span { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #404040; }
        .tile strong { font-size: 16px; font-variant-numeric: tabular-nums; }
        .scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        th, td { text-align: left; padding: 5px 6px; border-bottom: 1px solid #d4d4d4; vertical-align: top; overflow-wrap: anywhere; }
        thead th { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid #111; }
        .num { text-align: right; white-space: nowrap; }
        .mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; }
        tfoot td { font-weight: 700; border-top: 1px solid #111; border-bottom: 0; }
        .void td { text-decoration: line-through; color: #525252; }
        .void td.reason { text-decoration: none; }
        .note { margin-top: 8px; padding: 8px 10px; border: 1px dashed #737373; border-radius: 8px; }
        .foot { margin-top: 18px; padding-top: 10px; border-top: 1px dashed #a3a3a3; font-size: 11px; color: #404040; }
        @media print {
            @page { size: A4; margin: 12mm; }
            html, body { background: #fff; padding: 0; }
            .bar { display: none; }
            .sheet { border: 0; border-radius: 0; max-width: none; padding: 0; }
            * { color: #000 !important; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <nav class="bar" aria-label="Summary actions">
        <a href="{{ route('owner.reports.income', ['view' => 'daily', 'date' => $period->date]) }}">Back to the report</a>
        <button type="button" onclick="window.print()">Print summary</button>
    </nav>

    <main class="sheet" aria-labelledby="eod-title">
        <header>
            <div>
                <h1 id="eod-title">End-of-day summary</h1>
                <div class="muted">{{ $shopName }}@if ($shop['address']) · {{ $shop['address'] }}@endif</div>
            </div>
            <div style="text-align: right">
                <strong>{{ $date->format('l, F j, Y') }}</strong>
                <div class="muted">Printed {{ LocalTime::format(Carbon::now('UTC')) }}</div>
            </div>
        </header>

        <div class="tiles">
            <div class="tile"><span>Income</span><strong>{{ Money::cents($r['cents']) }}</strong></div>
            <div class="tile"><span>Payments</span><strong>{{ $r['count'] }}</strong></div>
            <div class="tile"><span>Cash</span><strong>{{ Money::cents($r['methods']['cash']['cents']) }}</strong> <small>({{ $r['methods']['cash']['count'] }})</small></div>
            <div class="tile"><span>GCash</span><strong>{{ Money::cents($r['methods']['gcash']['cents']) }}</strong> <small>({{ $r['methods']['gcash']['count'] }})</small></div>
            <div class="tile"><span>Walk-in / Online</span><strong>{{ $r['sources']['walk_in']['count'] }} / {{ $r['sources']['online']['count'] }}</strong></div>
        </div>
        <p class="note">Cash expected in the drawer: the Cash total above ({{ Money::cents($r['methods']['cash']['cents']) }}).</p>

        <h2>Payments of the day</h2>
        @if ($day['recorded']->isEmpty())
            <p class="muted">No payments were recorded on this day.</p>
        @else
            <div class="scroll">
                <table>
                    <caption class="muted" style="text-align: left; padding-bottom: 4px">Recorded payments, the earliest first</caption>
                    <thead><tr><th scope="col">Payment no.</th><th scope="col">Time</th><th scope="col">Customer</th><th scope="col">Reference</th><th scope="col">Method</th><th scope="col" class="num">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($day['recorded'] as $payment)
                            <tr>
                                <td class="mono">{{ $payment->reference_number }}</td>
                                <td>{{ LocalTime::format($payment->received_at, 'g:i A') }}</td>
                                <td>{{ $payment->payable?->customer?->name ?? 'Deleted account' }}</td>
                                <td><span class="mono">{{ $payment->payable?->reference_number ?? '—' }}</span> <span class="muted">· {{ $what($payment->payable) }}{{ $payment->payable?->isWalkIn() ? ' · Walk-in' : '' }}</span></td>
                                <td>{{ $payment->methodText() }}</td>
                                <td class="num">{{ Money::format($payment->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td colspan="5">Income ({{ $r['count'] }} {{ Str::plural('payment', $r['count']) }})</td><td class="num">{{ Money::cents($r['cents']) }}</td></tr></tfoot>
                </table>
            </div>
        @endif

        <h2>Voided payments (not counted)</h2>
        @if ($day['voided']->isEmpty())
            <p class="muted">None.</p>
        @else
            <div class="scroll">
                <table>
                    <caption class="muted" style="text-align: left; padding-bottom: 4px">Voided payment records of the day. They are not part of the income above.</caption>
                    <thead><tr><th scope="col">Payment no.</th><th scope="col">Time</th><th scope="col">Customer</th><th scope="col">Reference</th><th scope="col" class="num">Amount</th><th scope="col">Reason</th></tr></thead>
                    <tbody>
                        @foreach ($day['voided'] as $payment)
                            <tr class="void">
                                <td class="mono">{{ $payment->reference_number }}</td>
                                <td>{{ LocalTime::format($payment->received_at, 'g:i A') }}</td>
                                <td>{{ $payment->payable?->customer?->name ?? 'Deleted account' }}</td>
                                <td class="mono">{{ $payment->payable?->reference_number ?? '—' }}</td>
                                <td class="num">{{ Money::format($payment->amount) }}</td>
                                <td class="reason">VOID · {{ $payment->void_reason }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <h2>Still awaiting payment</h2>
        <p>{{ $r['awaiting']['count'] }} confirmed {{ Str::plural('price', $r['awaiting']['count']) }} not paid yet: {{ Money::cents($r['awaiting']['cents']) }} (right now, any date; not income).</p>

        <p class="foot">Prepared from the system’s payment records. Not an official document. Payment is made face-to-face at {{ $shopName }}.</p>
    </main>
</body>
</html>
