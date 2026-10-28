<?php

namespace App\Http\Controllers\Owner;

use App\Exceptions\BookingTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Bookings\CancelBookingRequest;
use App\Http\Requests\Owner\Bookings\ChangePriceRequest;
use App\Http\Requests\Owner\Bookings\CompleteBookingRequest;
use App\Http\Requests\Owner\Bookings\ConfirmBookingRequest;
use App\Http\Requests\Owner\Bookings\InternalNotesRequest;
use App\Http\Requests\Owner\Bookings\ProposeTimeRequest;
use App\Http\Requests\Owner\Bookings\RejectBookingRequest;
use App\Models\PhotographyBooking;
use App\Services\BookingTransitionService;
use App\Support\LocalTime;
use App\Support\Money;
use Closure;
use Illuminate\Http\RedirectResponse;

/**
 * The studio's work on a booking. Every action goes through BookingTransitionService, which locks the booking, checks
 * the status table and writes the history; nothing here sets a status, a time or a price itself. A refused action
 * (a page that was out of date, a double click, a time that is no longer free) comes back as a message.
 */
class PhotographyBookingActionController extends Controller
{
    public function __construct(private readonly BookingTransitionService $transitions) {}

    public function confirm(ConfirmBookingRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        return $this->act($booking, fn () => $this->transitions->confirm($booking, $request->user(), $request->price(), $request->note(), $request->outsideSchedule()),
            fn () => 'Booking confirmed for '.LocalTime::format($booking->starts_at).' at '.Money::format($booking->confirmed_price).'.');
    }

    public function propose(ProposeTimeRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        return $this->act($booking, fn () => $this->transitions->propose($booking, $request->user(), $request->startsAt(), (string) $request->note(), $request->price()),
            fn () => 'New time offered: '.LocalTime::format($booking->proposed_starts_at).'. The customer can accept it or ask for another time.');
    }

    public function reject(RejectBookingRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        return $this->act($booking, fn () => $this->transitions->reject($booking, $request->user(), $request->reason()),
            fn () => 'Booking rejected. The customer can see your reason.');
    }

    public function complete(CompleteBookingRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        return $this->act($booking, fn () => $this->transitions->complete($booking, $request->user(), $request->note()),
            fn () => 'Photoshoot marked as completed.');
    }

    public function cancel(CancelBookingRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        return $this->act($booking, fn () => $this->transitions->cancel($booking, $request->user(), $request->reason()),
            fn () => 'Booking cancelled.');
    }

    public function price(ChangePriceRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        return $this->act($booking, fn () => $this->transitions->changePrice($booking, $request->user(), $request->newPrice(), $request->reason()),
            fn () => 'Confirmed price is now '.Money::format($booking->confirmed_price).'.');
    }

    public function notes(InternalNotesRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        return $this->act($booking, fn () => $this->transitions->saveInternalNote($booking, $request->user(), $request->notes()),
            fn () => 'Internal notes saved. Only the studio can see them.', '#internal-notes');
    }

    /** @param  Closure(): string  $done  read after the change, so it shows the saved values */
    private function act(PhotographyBooking $booking, Closure $action, Closure $done, string $anchor = '#actions'): RedirectResponse
    {
        $back = redirect()->to(route('owner.bookings.show', $booking).$anchor);

        try {
            $action();
        } catch (BookingTransitionException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('status', $done());
    }
}
