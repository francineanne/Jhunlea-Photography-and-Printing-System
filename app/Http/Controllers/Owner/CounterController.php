<?php

namespace App\Http\Controllers\Owner;

use App\Enums\PaymentMethod;
use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Payments\CounterPaymentRequest;
use App\Http\Requests\Owner\WalkIn\WalkInLookupRequest;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Services\Counter\CounterService;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The shop counter on the Payments page (Phase 8B): find an order or booking, record its full payment through
 * PaymentService (every Phase 5 rule unchanged), and print a payment receipt. The modal talks JSON; without JavaScript
 * the same POST redirects back to the Payments page.
 */
class CounterController extends Controller
{
    public function __construct(private readonly CounterService $counter) {}

    /** Orders and bookings by reference, customer name or phone (JSON). */
    public function search(WalkInLookupRequest $request): JsonResponse
    {
        return response()->json(['results' => $this->counter->search((string) $request->validated('q'))
            ->map(fn (PrintingOrder|PhotographyBooking $record) => $this->counter->describe($record))->values()]);
    }

    /** One order or booking for the modal, opened from a queue or a detail page (JSON). */
    public function target(Request $request): JsonResponse
    {
        $record = $this->counter->find((string) $request->query('type'), (int) $request->query('id'));

        return $record === null
            ? response()->json(['message' => 'That order or booking was not found.'], 404)
            : response()->json(['target' => $this->counter->describe($record)]);
    }

    public function record(CounterPaymentRequest $request, PaymentService $payments): JsonResponse|RedirectResponse
    {
        $record = $this->counter->find((string) $request->validated('payable_type'), (int) $request->validated('payable_id'));
        $error = $record === null ? 'That order or booking was not found.' : $this->counter->blocker($record);
        $tendered = $request->validated('cash_tendered');

        // The change calculator only: cash received below the full amount is a mistake, never a partial payment
        if ($error === null && $tendered !== null && $request->paymentMethod() === PaymentMethod::Cash
            && Money::toCents($tendered) < Money::toCents((string) $request->validated('expected_amount'))) {
            $error = 'The cash received is less than the full amount. Collect the full amount; partial payments are not taken.';
        }

        if ($error === null) {
            try {
                $payment = $payments->record($record, $request->user(), $request->paymentMethod(), $request->gcashReference(), (string) $request->validated('expected_amount'));
            } catch (PaymentException $e) {
                $error = $e->getMessage();
            }
        }

        if ($error !== null) {
            return $request->expectsJson()
                ? response()->json(['message' => $error, 'errors' => ['payment' => [$error]]], 422)
                : redirect()->route('owner.payments.index')->with('error', $error);
        }

        $change = $tendered !== null && $payment->method === PaymentMethod::Cash
            ? Money::format(Money::fromCents(max(0, Money::toCents($tendered) - Money::toCents($payment->amount))))
            : null;
        $receipt = route('owner.payments.receipt', $payment);

        if (! $request->expectsJson()) {
            return redirect()->route('owner.payments.index')->with('status', 'Payment '.$payment->reference_number.' recorded: '.Money::format($payment->amount).'.')
                ->with('receipt_url', $receipt);
        }

        return response()->json([
            'reference' => $payment->reference_number,
            'amount' => Money::format($payment->amount),
            'method' => $payment->methodText(),
            'change' => $change,
            'receipt_url' => $receipt,
            'for' => $record->reference_number,
        ]);
    }

    /** The printable payment receipt (not an official receipt). Reprinting is always allowed. */
    public function receipt(Payment $payment, ShopSettings $shop): View
    {
        // Payment::payable() already includes removed orders and bookings, so an old receipt still prints
        $payment->load(['payable.customer:id,name', 'receiver:id,name', 'voider:id,name']);

        return view('owner.payments.receipt', ['payment' => $payment, 'shop' => $shop->business()]);
    }
}
