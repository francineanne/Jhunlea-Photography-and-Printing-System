<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StorePhotographyBookingRequest;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Services\AvailabilityService;
use App\Services\BookingImageService;
use App\Services\BookingService;
use App\Support\LocalTime;
use App\Support\ShopSettings;
use App\Support\Slot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The customer's photoshoot booking wizard (Phase 4B). The six steps are sections of one form, so it works without
 * scripts; Alpine shows them one at a time and fills the calendar from BookingSlotsController. Everything is checked
 * again on the server in one POST, and BookingService::place() saves a REQUEST: the studio confirms it later.
 */
class PhotographyBookingController extends Controller
{
    /** Which wizard step holds each field, so a failed submit opens the step with the first problem. */
    private const STEP_OF_FIELD = [
        'package' => 1,
        'group_size' => 2, 'contact_number' => 2, 'notes' => 2,
        'starts_at' => 3,
        'theme' => 4, 'custom_theme_text' => 4, 'images' => 4,
        'prints' => 5,
        'booking' => 6,
    ];

    /** Without scripts there is no calendar: the next days with free times are listed as plain choices instead. */
    public const FALLBACK_DAYS = 7;

    public function create(Request $request, BookingService $bookings, AvailabilityService $availability, BookingImageService $images, ShopSettings $settings): View
    {
        Gate::authorize('create', PhotographyBooking::class);

        $packages = PhotographyPackage::published()
            ->with(['coverImage', 'themes' => fn ($query) => $query->published()])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $chosen = $this->chosenPackage($request, $packages);
        $errors = session('errors');
        $hasErrors = $errors instanceof ViewErrorBag && $errors->isNotEmpty();

        // A package picked on the public pages opens the wizard at the details step; an unknown address starts at step 1
        $startStep = $hasErrors ? $this->firstStepWithError($errors) : ($chosen !== null && ! session()->hasOldInput() ? 2 : 1);

        return view('customer.bookings.create', [
            'packages' => $packages,
            // What the wizard shows for each package as the customer switches between them
            'packageData' => $packages->mapWithKeys(fn (PhotographyPackage $package) => [$package->id => [
                'name' => $package->name,
                'price' => $package->priceText(),
                'minutes' => $package->duration_minutes,
                'max' => $package->max_people,
                'groupRule' => BookingService::groupSizeMessage($package),
            ]]),
            'chosen' => $chosen,
            'startStep' => $startStep,
            'atLimit' => $bookings->atRequestLimit($request->user()),
            'limitMessage' => BookingService::requestLimitMessage(),
            'windowText' => BookingSetting::current()->windowText(),
            'fallbackDays' => $chosen ? $this->nextFreeDays($availability, $chosen) : collect(),
            'oldSlot' => $this->oldSlot($chosen),
            'bookable' => array_map(fn ($moment) => LocalTime::format($moment, 'Y-m-d'), $availability->window()),
            'imageRules' => [
                'max' => $settings->bookingMaxImages(),
                'maxMb' => $settings->bookingMaxImageMb(),
                'totalMb' => $settings->bookingMaxTotalMb(),
                'extensions' => $images->allowedExtensions(),
                'types' => strtoupper(implode(', ', array_keys(config('booking.types')))),
                'tooLarge' => $images->tooLargeMessage(),
                'wrongType' => $images->wrongTypeMessage(),
                'tooMany' => $images->tooManyMessage(),
            ],
            // The same words the server answers with, for the checks the wizard makes before sending
            'messages' => [
                'package' => StorePhotographyBookingRequest::CHOOSE_PACKAGE,
                'groupSize' => StorePhotographyBookingRequest::ENTER_GROUP_SIZE,
                'contact' => BookingService::CONTACT_NUMBER_MESSAGE,
                'slot' => StorePhotographyBookingRequest::CHOOSE_SLOT,
                'theme' => BookingService::THEME_OR_IDEA_MESSAGE,
            ],
        ]);
    }

    public function store(StorePhotographyBookingRequest $request, BookingService $bookings): RedirectResponse
    {
        Gate::authorize('create', PhotographyBooking::class);

        try {
            $booking = $bookings->place(
                $request->user(),
                $request->package(),
                $request->theme(),
                $request->customThemeText(),
                $request->startsAt(),
                $request->groupSize(),
                $request->contactNumber(),
                $request->notes(),
                $request->wantsPrints(),
                $request->uploads(),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Your request could not be saved, so nothing was sent. Please choose your images again and try once more.');
        }

        return redirect()->route('customer.bookings.sent', $booking);
    }

    public function sent(PhotographyBooking $booking): View
    {
        Gate::authorize('view', $booking);

        $booking->load(['images' => fn ($query) => $query->orderBy('id')]);

        return view('customer.bookings.sent', ['booking' => $booking]);
    }

    /** The package typed before a failed submit, or the one picked on the public pages (?package=slug). */
    private function chosenPackage(Request $request, Collection $packages): ?PhotographyPackage
    {
        $old = old('package');

        if (filter_var($old, FILTER_VALIDATE_INT) !== false) {
            return $packages->firstWhere('id', (int) $old);
        }

        $slug = $request->query('package');

        return is_string($slug) ? $packages->firstWhere('slug', $slug) : null;
    }

    /**
     * The next days that have free times for this package, for the list shown without scripts.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Slot>>
     */
    private function nextFreeDays(AvailabilityService $availability, PhotographyPackage $package): \Illuminate\Support\Collection
    {
        [$earliest, $latest] = $availability->window();
        $from = LocalTime::in($earliest)->startOfDay();
        $to = LocalTime::in($latest)->startOfDay()->min($from->copy()->addDays((int) config('booking.max_lookup_days') - 1));

        if ($to->lt($from)) {
            return collect();
        }

        return $availability->slots($package->duration_minutes, $from->format('Y-m-d'), $to->format('Y-m-d'))
            ->groupBy(fn (Slot $slot) => $slot->localDate())
            ->take(self::FALLBACK_DAYS);
    }

    /** The time chosen before a failed submit, so the wizard can show it again (the server checks it again anyway). */
    private function oldSlot(?PhotographyPackage $package): ?array
    {
        $value = old('starts_at');

        if ($package === null || ! is_string($value)) {
            return null;
        }

        try {
            $start = Carbon::createFromFormat(StorePhotographyBookingRequest::STARTS_AT_FORMAT, $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return (new Slot($start, $start->copy()->addMinutes($package->duration_minutes)))->toArray();
    }

    private function firstStepWithError(ViewErrorBag $errors): int
    {
        return collect($errors->keys())->map(fn (string $key) => self::STEP_OF_FIELD[strtok($key, '.')] ?? 6)->min();
    }
}
