<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\BookingSlotsRequest;
use App\Services\AvailabilityService;
use App\Support\LocalTime;
use App\Support\Slot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * The free times of the booking wizard's calendar, worked out by AvailabilityService for the chosen package. The
 * browser never works out availability itself: a day missing from "days" has no free time, for whatever reason (closed,
 * blocked or fully booked; "info" says which in plain words, and who booked is never told). The first and last day one may book come from the same rules.
 */
class BookingSlotsController extends Controller
{
    public function __invoke(BookingSlotsRequest $request, AvailabilityService $availability): JsonResponse
    {
        $package = $request->package();
        [$earliest, $latest] = $availability->window();

        $overview = $availability->overview($package->duration_minutes, $request->from()->format('Y-m-d'), $request->to()->format('Y-m-d'));

        $days = $overview->flatMap(fn (array $day) => $day['slots'])
            ->map(fn (Slot $slot) => $slot->toArray())
            ->groupBy('date');

        return response()->json([
            'package' => $package->id,
            'from' => $request->from()->format('Y-m-d'),
            'to' => $request->to()->format('Y-m-d'),
            'bookable_from' => LocalTime::format($earliest, 'Y-m-d'),
            'bookable_until' => LocalTime::format($latest, 'Y-m-d'),
            'days' => (object) $days->all(),
            // Phase 10A: why a day has no time, and its timeline (the photoshoot hours with the time that is not
            // available shaded). Never who holds a time, and no booking detail
            'info' => (object) $overview->map(fn (array $day) => self::dayInfo($day))->all(),
            'not_shown' => AvailabilityService::NOT_SHOWN_TEXT,
        ]);
    }

    /**
     * @param  array{state: string, opens: ?Carbon, closes: ?Carbon, busy: list<array{0: Carbon, 1: Carbon}>}  $day
     * @return array{state: string, text: ?string, timeline: ?array{from: string, to: string, busy: list<array{left: float, width: float, from: string, to: string}>}}
     */
    public static function dayInfo(array $day): array
    {
        $timeline = null;

        if ($day['opens'] !== null && $day['closes'] !== null) {
            $start = $day['opens']->getTimestamp();
            $length = max(1, $day['closes']->getTimestamp() - $start);
            $timeline = [
                'from' => LocalTime::format($day['opens'], 'g:i A'),
                'to' => LocalTime::format($day['closes'], 'g:i A'),
                'busy' => array_map(fn (array $range) => [
                    'left' => round(($range[0]->getTimestamp() - $start) / $length * 100, 2),
                    'width' => round(($range[1]->getTimestamp() - $range[0]->getTimestamp()) / $length * 100, 2),
                    'from' => LocalTime::format($range[0], 'g:i A'),
                    'to' => LocalTime::format($range[1], 'g:i A'),
                ], $day['busy']),
            ];
        }

        return [
            'state' => $day['state'],
            'text' => $day['state'] === AvailabilityService::OPEN ? null : AvailabilityService::dayText($day['state']),
            'timeline' => $timeline,
        ];
    }
}
