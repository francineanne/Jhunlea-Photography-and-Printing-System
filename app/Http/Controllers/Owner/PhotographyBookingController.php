<?php

namespace App\Http\Controllers\Owner;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Bookings\BookingListRequest;
use App\Http\Requests\Owner\Bookings\BookingShowRequest;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Services\AvailabilityService;
use App\Services\ScheduleConflictService;
use App\Support\LocalTime;
use App\Support\Slot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The owner's photography bookings (Phase 4C): the list with a tab per status, and one booking with everything the
 * studio needs to decide on it, including the schedule panel (clashes, competing requests, rules the time breaks).
 */
class PhotographyBookingController extends Controller
{
    private const PER_PAGE = 15;

    /** How many days with free times the proposal panel lists, from the day the owner picks. */
    public const PROPOSAL_DAYS = 7;

    /**
     * Confirmed sessions are listed by start time, soonest first (the studio's agenda); every other tab, and All,
     * newest request first.
     */
    public function index(BookingListRequest $request): View
    {
        $status = $request->status();
        $search = $request->search();

        $bookings = PhotographyBooking::query()
            ->with(['customer', 'package', 'recordedPayment'])
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($search) {
                // Bound parameters, and % _ \ typed by the owner are matched literally instead of as wildcards
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('reference_number', 'like', $like)
                    ->orWhere('contact_number', 'like', $like)
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $like)));
            })
            ->when($request->package(), fn (Builder $query, int $package) => $query->where('photography_package_id', $package))
            // The session's day in the shop's calendar, as the UTC range that holds it
            ->when($request->from(), fn (Builder $query, string $from) => $query->where('starts_at', '>=', LocalTime::dayStartUtc($from)))
            ->when($request->to(), fn (Builder $query, string $to) => $query->where('starts_at', '<=', LocalTime::dayEndUtc($to)))
            ->when($status === BookingStatus::Confirmed,
                fn (Builder $query) => $query->orderBy('starts_at')->orderBy('id'),
                fn (Builder $query) => $query->latest()->latest('id'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $counts = PhotographyBooking::query()->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total);

        return view('owner.bookings.index', [
            'bookings' => $bookings,
            'status' => $status,
            'search' => $search,
            'package' => $request->package(),
            'from' => $request->from(),
            'to' => $request->to(),
            'hasFilters' => $search !== '' || $request->package() || $request->from() || $request->to(),
            // Every booking, whatever the filters, with a count on every tab (0 too), as in the design
            'counts' => $counts,
            'statuses' => BookingStatus::cases(),
            'packages' => PhotographyPackage::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']),
        ]);
    }

    public function show(BookingShowRequest $request, PhotographyBooking $booking, ScheduleConflictService $conflicts, AvailabilityService $availability): View
    {
        $booking->load([
            'customer',
            'package',
            'theme',
            'images' => fn ($query) => $query->orderBy('id'),
            'statusHistories.changedBy',
            'printingOrders' => fn ($query) => $query->latest('id'),
            'printingOrders.recordedPayment',
            'payments.receiver',
            'payments.voider',
            'recordedPayment',
        ]);

        // Which buttons to show; the service checks the same table again when one is pressed
        $moves = collect(array_keys($booking->status->transitions()))
            ->map(fn (string $to) => BookingStatus::from($to))
            ->filter(fn (BookingStatus $to) => $booking->status->canMoveTo($to, UserRole::Owner))
            ->values();

        $schedule = null;

        if ($booking->status === BookingStatus::Requested) {
            $found = $conflicts->conflictsFor($booking->starts_at, $booking->ends_at, $booking->id);
            $schedule = [
                'blocking' => $found['blocking'],
                'competing' => $found['competing'],
                'free' => $conflicts->isFree($booking->starts_at, $booking->ends_at, $booking->id),
                'rules' => $availability->ruleViolations($booking->durationMinutes(), $booking->starts_at),
            ];
        }

        $canPropose = $moves->contains(BookingStatus::Proposed);
        [$proposeFrom, $proposalDays] = $canPropose ? $this->proposalDays($availability, $booking, $request->proposeFrom()) : [null, collect()];

        return view('owner.bookings.show', [
            'booking' => $booking,
            'moves' => $moves,
            'schedule' => $schedule,
            'canPropose' => $canPropose,
            'proposeFrom' => $proposeFrom,
            'proposalDays' => $proposalDays,
            // A recorded payment locks the price (BookingTransitionService refuses the change until it is voided)
            'canChangePrice' => $booking->status->allowsPriceChange() && $booking->recordedPayment === null,
            'startedYet' => $booking->starts_at->lte(Carbon::now('UTC')),
        ]);
    }

    /**
     * Free times of this booking's length for a proposal, from the day picked (or the requested day, or the first day
     * one may book), leaving this booking itself out of the bookings that hold time.
     *
     * @return array{0: string, 1: Collection<string, Collection<int, Slot>>}
     */
    private function proposalDays(AvailabilityService $availability, PhotographyBooking $booking, ?string $picked): array
    {
        [$earliest, $latest] = $availability->window();
        $first = LocalTime::format($earliest, 'Y-m-d');
        $last = LocalTime::format($latest, 'Y-m-d');
        $from = max($picked ?? LocalTime::format($booking->starts_at, 'Y-m-d'), $first);

        if ($from > $last) {
            return [$from, collect()];
        }

        $to = min(Carbon::parse($from)->addDays((int) config('booking.max_lookup_days') - 1)->format('Y-m-d'), $last);

        $days = $availability->slots($booking->durationMinutes(), $from, $to, $booking->id)
            ->groupBy(fn (Slot $slot) => $slot->localDate())
            ->take(self::PROPOSAL_DAYS);

        return [$from, $days];
    }
}
