<?php

namespace App\Http\Controllers\Owner;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Payments\PaymentActionRequest;
use App\Http\Requests\Owner\Payments\RecordPaymentRequest;
use App\Http\Requests\Owner\Payments\VoidPaymentRequest;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Services\PaymentService;
use App\Support\Money;
use Closure;
use Illuminate\Http\RedirectResponse;

/**
 * Recording and voiding payments on printing orders and photography bookings. Everything goes through PaymentService;
 * a refused action (a double click, a stale page) comes back as a message on the payment card, not an error page.
 */
class PaymentActionController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function recordOrder(RecordPaymentRequest $request, PrintingOrder $order): RedirectResponse
    {
        return $this->record($request);
    }

    public function recordBooking(RecordPaymentRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        return $this->record($request);
    }

    public function voidOrder(VoidPaymentRequest $request, PrintingOrder $order, Payment $payment): RedirectResponse
    {
        return $this->void($request);
    }

    public function voidBooking(VoidPaymentRequest $request, PhotographyBooking $booking, Payment $payment): RedirectResponse
    {
        return $this->void($request);
    }

    private function record(RecordPaymentRequest $request): RedirectResponse
    {
        return $this->act($request, function () use ($request) {
            $payment = $this->payments->record($request->payable(), $request->user(), $request->paymentMethod(), $request->gcashReference(), $request->expectedAmount());

            return 'Payment '.$payment->reference_number.' recorded: '.Money::format($payment->amount).' by '.$payment->method->label().'.';
        });
    }

    private function void(VoidPaymentRequest $request): RedirectResponse
    {
        return $this->act($request, function () use ($request) {
            $payment = $this->payments->void($request->payable(), $request->payment(), $request->user(), $request->reason());

            return 'Payment '.$payment->reference_number.' was voided. It no longer counts, and the payment can be recorded again.';
        });
    }

    /** @param  Closure(): string  $action */
    private function act(PaymentActionRequest $request, Closure $action): RedirectResponse
    {
        $back = redirect()->to($request->backUrl());

        try {
            return $back->with('status', $action());
        } catch (PaymentException $e) {
            return $back->withInput()->with('error', $e->getMessage());
        }
    }
}
