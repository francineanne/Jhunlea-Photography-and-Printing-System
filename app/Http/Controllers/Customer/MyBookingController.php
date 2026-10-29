<?php

namespace App\Http\Controllers\Customer;

use App\Enums\BookingStatus;
use App\Exceptions\BookingTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Bookings\AcceptProposalRequest;
use App\Http\Requests\Customer\Bookings\AskForAnotherTimeRequest;
use App\Http\Requests\Customer\Bookings\BookingListRequest;
use App\Http\Requests\Customer\Bookings\CancelBookingRequest;
use App\Models\PhotographyBooking;
use App\Services\BookingTransitionService;
use App\Support\LocalTime;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The customer's own photoshoot bookings (Phase 4C): the list, one booking, cancelling, and answering a time the studio
 * proposed. Every query starts from the signed-in customer, so another customer's booking is never loaded into a
 * list. Every change goes through BookingTransitionService.
 */
class MyBookingController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(private readonly BookingTransitionService $transitions) {}

    /**
     * Upcoming confirmed sessions come first, soonest first (what the customer needs next); everything else follows,
     * newest request first.
     */
    public function index(BookingListRequest $request): View
    {
        $status = $request->status();
        $mine = fn () => PhotographyBooking::query()->where('customer_id', $request->user()->id);
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $upcoming = 'case when status = ? and starts_at >= ? then 0 else 1 end';

        $bookings = $mine()
            ->with(['package.coverImage', 'recordedPayment'])
            ->withCount('images')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByRaw($upcoming, [BookingStatus::Confirmed->value, $now])
            ->orderByRaw('case when status = ? and starts_at >= ? then starts_at end asc', [BookingStatus::Confirmed->value, $now])
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('customer.bookings.index', [
            'bookings' => $bookings,
            'status' => $status,
            'counts' => $mine()->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total),
            'statuses' => BookingStatus::cases(),
        ]);
    }

    public function show(PhotographyBooking $booking): View
    {
        Gate::authorize('view', $booking);

        // Only this customer's own printing orders, whatever is linked
        $booking->load([
            'images' => fn ($query) => $query->orderBy('id'),
            'statusHistories',
            'printingOrders' => fn ($query) => $query->where('customer_id', $booking->customer_id)->latest('id'),
            'printingOrders.recordedPayment',
            'payments',
            'recordedPayment',
        ]);

        $cutoff = (int) config('booking.customer_cancel_cutoff_hours');

        return view('customer.bookings.show', [
            'booking' => $booking,
            'canCancel' => Gate::allows('cancel', $booking),
            'canRespond' => Gate::allows('respondToProposal', $booking),
            'canOrderPrints' => Gate::allows('orderPrints', $booking),
            'cutoffHours' => $cutoff,
            // A confirmed session the customer can no longer cancel here: they talk to the studio instead
            'pastCutoff' => $booking->status === BookingStatus::Confirmed && ! Gate::allows('cancel', $booking),
            'cutoffAt' => $booking->status === BookingStatus::Confirmed ? $booking->starts_at->copy()->subHours($cutoff) : null,
        ]);
    }

    public function cancel(CancelBookingRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        return $this->act($booking, fn () => $this->transitions->cancel($booking, $request->user(), $request->reason()),
            "Booking {$booking->reference_number} was cancelled.");
    }

    public function accept(AcceptProposalRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        try {
            $this->transitions->acceptProposal($booking, $request->user());
        } catch (BookingTransitionException $e) {
            // The studio's words ("propose another time") are not for the customer: say what happened to them
            $message = in_array($e->getCode(), [BookingTransitionException::TIME_TAKEN, BookingTransitionException::TIME_NOT_OPEN], true)
                ? 'That time is no longer free, so your booking was not changed. You can ask the studio for another time below.'
                : $e->getMessage();

            return $this->back($booking)->with('error', $message);
        }

        return $this->back($booking)->with('status', 'Your photoshoot is confirmed for '.LocalTime::format($booking->starts_at).'.');
    }

    public function anotherTime(AskForAnotherTimeRequest $request, PhotographyBooking $booking): RedirectResponse
    {
        return $this->act($booking, fn () => $this->transitions->askForAnotherTime($booking, $request->user(), $request->note()),
            'Your note was sent. The studio will look for another time.');
    }

    private function act(PhotographyBooking $booking, Closure $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (BookingTransitionException $e) {
            return $this->back($booking)->with('error', $e->getMessage());
        }

        return $this->back($booking)->with('status', $done);
    }

    private function back(PhotographyBooking $booking): RedirectResponse
    {
        return redirect()->route('customer.bookings.show', $booking);
    }
}
