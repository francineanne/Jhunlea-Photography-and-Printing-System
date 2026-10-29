<?php

namespace App\Http\Requests\Owner\Payments;

use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base of the owner's payment actions on a printing order or a photography booking (whichever the route names).
 * PaymentService decides what is allowed; this checks the owner may act and validates what was typed.
 */
abstract class PaymentActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('recordPayment', $this->payable());
    }

    /** A refused action returns to the payment card, where the errors are shown. */
    protected function getRedirectUrl(): string
    {
        return $this->backUrl();
    }

    public function payable(): PrintingOrder|PhotographyBooking
    {
        return $this->route('order') ?? $this->route('booking');
    }

    public function backUrl(): string
    {
        $payable = $this->payable();

        return ($payable instanceof PrintingOrder ? route('owner.orders.show', $payable) : route('owner.bookings.show', $payable)).'#payment';
    }
}
