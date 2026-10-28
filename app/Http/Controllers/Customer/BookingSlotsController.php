<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\BookingSlotsRequest;
use App\Services\AvailabilityService;
use App\Support\LocalTime;
use App\Support\Slot;
use Illuminate\Http\JsonResponse;

/**
 * The free times of the booking wizard's calendar, worked out by AvailabilityService for the chosen package. The
 * browser never works out availability itself: a day missing from "days" has no free time, for whatever reason (closed,
 * blocked or fully booked; who booked is never told). The first and last day one may book come from the same rules.
 */
class BookingSlotsController extends Controller
{
    public function __invoke(BookingSlotsRequest $request, AvailabilityService $availability): JsonResponse
    {
        $package = $request->package();
        [$earliest, $latest] = $availability->window();

        $days = $availability->slots($package->duration_minutes, $request->from()->format('Y-m-d'), $request->to()->format('Y-m-d'))
            ->map(fn (Slot $slot) => $slot->toArray())
            ->groupBy('date');

        return response()->json([
            'package' => $package->id,
            'from' => $request->from()->format('Y-m-d'),
            'to' => $request->to()->format('Y-m-d'),
            'bookable_from' => LocalTime::format($earliest, 'Y-m-d'),
            'bookable_until' => LocalTime::format($latest, 'Y-m-d'),
            'days' => (object) $days->all(),
        ]);
    }
}
