{{--
    The payment receipt of one payment (Phase 8B), printable on a normal A4 printer. It is NOT an official receipt and
    says so. A page of its own on purpose: no navigation, no dark mode, no external fonts or scripts, black on white,
    a compact block at the top of the page. A voided payment prints with a clear VOID mark and its reason.
--}}
@php
    use App\Models\PrintingOrder;
    use App\Support\LocalTime;
    use App\Support\Money;

    $payable = $payment->payable;
    $isOrder = $payable instanceof PrintingOrder;
    $what = $payable === null ? 'Removed record' : ($isOrder ? $payable->serviceName() : ($payable->snapshot['package']['name'] ?? 'Photoshoot'));
    $voided = ! $payment->isRecorded();
    $shopName = $shop['shop_name'] ?: config('app.name');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Payment receipt {{ $payment->reference_number }}</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #f5f5f5; color: #111; color-scheme: light; }
        body { font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; padding: 24px 16px; }
        .bar { max-width: 148mm; margin: 0 auto 16px; display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; align-items: center; }
        .bar a, .bar button { min-height: 44px; padding: 0 18px; border-radius: 10px; font: 600 14px/44px system-ui, sans-serif; text-decoration: none; cursor: pointer; }
        .bar a { color: #111; border: 1px solid #d4d4d4; background: #fff; }
        .bar button { color: #fff; background: #c62828; border: 0; }
        .bar a:focus-visible, .bar button:focus-visible { outline: 3px solid #c62828; outline-offset: 2px; }
        .receipt { position: relative; max-width: 148mm; margin: 0 auto; background: #fff; border: 1px solid #d4d4d4; border-radius: 12px; padding: 24px; overflow: hidden; }
        .shop { text-align: center; border-bottom: 1px dashed #a3a3a3; padding-bottom: 12px; }
        .shop h1 { margin: 0; font-size: 18px; letter-spacing: .02em; }
        .shop p { margin: 2px 0 0; font-size: 12px; color: #404040; white-space: pre-line; overflow-wrap: anywhere; }
        .title { text-align: center; margin: 14px 0 2px; font-size: 16px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        .notice { text-align: center; margin: 0 0 14px; font-size: 12px; font-weight: 600; }
        dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; }
        dt { color: #525252; }
        dd { margin: 0; text-align: right; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
        .mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; }
        .amount { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #a3a3a3; display: flex; justify-content: space-between; align-items: baseline; font-size: 18px; font-weight: 700; }
        .foot { margin-top: 14px; padding-top: 10px; border-top: 1px dashed #a3a3a3; font-size: 11px; color: #404040; text-align: center; }
        .void-mark { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
        .void-mark span { transform: rotate(-24deg); border: 6px solid #b91c1c; color: #b91c1c; padding: 4px 24px; font: 800 64px/1 system-ui, sans-serif; letter-spacing: .12em; opacity: .85; }
        .void-reason { margin-top: 12px; border: 2px solid #b91c1c; border-radius: 8px; padding: 8px 10px; font-size: 12px; overflow-wrap: anywhere; }
        @media print {
            @page { size: A4; margin: 12mm; }
            html, body { background: #fff; padding: 0; }
            .bar { display: none; }
            .receipt { border: 1px solid #000; border-radius: 0; max-width: 120mm; margin: 0; box-shadow: none; }
            * { color: #000 !important; }
            .void-mark span { border-color: #000; opacity: .35; }
            .void-reason { border-color: #000; }
        }
    </style>
</head>
<body>
    <nav class="bar" aria-label="Receipt actions">
        <a href="{{ route('owner.payments.index') }}">Back to Payments</a>
        <button type="button" onclick="window.print()">Print receipt</button>
    </nav>

    <main class="receipt" aria-labelledby="receipt-title">
        @if ($voided)
            <div class="void-mark" aria-hidden="true"><span>VOID</span></div>
        @endif

        <header class="shop">
            <h1>{{ $shopName }}</h1>
            @if ($shop['address'])<p>{{ $shop['address'] }}</p>@endif
            @if ($shop['phone'] || $shop['email'])
                <p>{{ collect([$shop['phone'], $shop['email']])->filter()->implode(' · ') }}</p>
            @endif
        </header>

        <p id="receipt-title" class="title">Payment receipt{{ $voided ? ' (void)' : '' }}</p>
        <p class="notice">This is not an official receipt.</p>

        <dl>
            <dt>Payment no.</dt><dd class="mono">{{ $payment->reference_number }}</dd>
            <dt>Date and time</dt><dd>{{ LocalTime::format($payment->received_at, 'M j, Y g:i A') }}</dd>
            <dt>Customer</dt><dd>{{ $payable?->customer?->name ?? 'Deleted account' }}</dd>
            <dt>For</dt><dd><span class="mono">{{ $payable?->reference_number ?? '—' }}</span><br>{{ $what }}</dd>
            <dt>Method</dt><dd>{{ $payment->method->label() }}</dd>
            @if ($payment->gcash_reference)
                <dt>GCash reference</dt><dd class="mono">{{ $payment->gcash_reference }}</dd>
            @endif
            <dt>Recorded by</dt><dd>{{ $payment->receiver?->name ?? 'Deleted account' }}</dd>
        </dl>

        <div class="amount"><span>Amount paid</span><span>{{ Money::format($payment->amount) }}</span></div>

        @if ($voided)
            <div class="void-reason" role="note">
                <strong>VOID.</strong> This payment record was cancelled{{ $payment->voided_at ? ' on '.LocalTime::format($payment->voided_at, 'M j, Y g:i A') : '' }}{{ $payment->voider ? ' by '.$payment->voider->name : '' }}. It does not count as a payment.
                @if ($payment->void_reason)<br>Reason: {{ $payment->void_reason }}@endif
            </div>
        @endif

        <p class="foot">Payment is made face-to-face at {{ $shopName }}. Full payment, cash or GCash. This is not an official receipt.</p>
    </main>
</body>
</html>
