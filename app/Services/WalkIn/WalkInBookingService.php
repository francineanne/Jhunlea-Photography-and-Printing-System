<?php

namespace App\Services\WalkIn;

use App\Enums\BookingStatus;
use App\Enums\RecordSource;
use App\Enums\UserRole;
use App\Exceptions\BookingTransitionException;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\BookingTransitionService;
use App\Services\ReferenceNumberService;
use App\Services\ScheduleConflictService;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A walk-in photography booking made by the owner at the counter (Phase 8A). It is created Confirmed (source walk_in)
 * with its first history row, in one transaction under the same schedule lock as BookingTransitionService::confirm(),
 * so it blocks that time for online customers exactly like any confirmed booking.
 *
 * Rules: a clash with another confirmed booking is NEVER allowed (ScheduleConflictService, the same message as
 * confirm()); a time that has passed is never allowed; the minimum notice and how far ahead one may book do not apply
 * (the customer is at the counter); opening hours and blocked dates apply unless the owner says "confirm anyway".
 * The price is optional: it may be set now or later, and the booking is paid on the day of the shoot with the usual
 * record-payment flow. The counter token makes a double click save one booking. Phase 8B may call place() as it is.
 */
class WalkInBookingService
{
    public function __construct(
        private readonly ReferenceNumberService $references,
        private readonly ScheduleConflictService $conflicts,
        private readonly AvailabilityService $availability,
        private readonly BookingTransitionService $transitions,
        private readonly BookingService $bookings,
    ) {}

    /**
     * @return array{booking: PhotographyBooking, created: bool}
     *
     * @throws AuthorizationException when the actor is not the owner
     * @throws ValidationException when a choice is refused (the key names the field)
     * @throws BookingTransitionException when the time clashes, has passed, or breaks a schedule rule without "anyway"
     */
    public function place(
        User $owner,
        User $customer,
        PhotographyPackage $package,
        ?Theme $theme,
        ?string $customThemeText,
        CarbonInterface $startsAt,
        int $groupSize,
        string $contactNumber,
        ?string $notes,
        ?string $confirmedPrice,
        bool $outsideSchedule,
        string $counterToken,
    ): array {
        if ($owner->role !== UserRole::Owner) {
            throw new AuthorizationException('Only the owner makes walk-in bookings.');
        }

        if ($customer->role !== UserRole::Customer) {
            $this->fail('customer_id', 'Choose a customer.');
        }

        if ($existing = $this->byToken($counterToken)) {
            return ['booking' => $existing, 'created' => false];
        }

        if ($package->trashed() || ! $package->is_published) {
            $this->fail('package', BookingService::PACKAGE_MESSAGE);
        }

        if ($theme !== null && ! BookingService::themeFits($package, $theme)) {
            $this->fail('theme', BookingService::THEME_MESSAGE);
        }

        $customThemeText = $customThemeText === null || trim($customThemeText) === '' ? null : trim($customThemeText);

        if ($customThemeText !== null && mb_strlen($customThemeText) > BookingService::CUSTOM_THEME_MAX) {
            $this->fail('custom_theme_text', BookingService::CUSTOM_THEME_MESSAGE);
        }

        if ($groupSize < 1 || $groupSize > $package->max_people) {
            $this->fail('group_size', BookingService::groupSizeMessage($package));
        }

        $contactNumber = trim($contactNumber);

        if (! BookingService::isContactNumber($contactNumber)) {
            $this->fail('contact_number', BookingService::CONTACT_NUMBER_MESSAGE);
        }

        $notes = $notes === null || trim($notes) === '' ? null : trim($notes);

        if ($notes !== null && mb_strlen($notes) > BookingService::NOTES_MAX) {
            $this->fail('notes', BookingService::NOTES_MESSAGE);
        }

        $price = $this->price($confirmedPrice);
        $start = Carbon::instance($startsAt)->utc();
        $end = $start->copy()->addMinutes($package->duration_minutes);

        try {
            return DB::transaction(function () use ($owner, $customer, $package, $theme, $customThemeText, $start, $end, $groupSize, $contactNumber, $notes, $price, $outsideSchedule, $counterToken) {
                $this->transitions->lockSchedule();

                if (! $this->conflicts->isFree($start, $end)) {
                    throw BookingTransitionException::conflict();
                }

                if ($start->lte(Carbon::now('UTC'))) {
                    throw BookingTransitionException::timePassed();
                }

                $reasons = $this->availability->ruleViolations($package->duration_minutes, $start, atCounter: true);

                if ($reasons !== [] && ! $outsideSchedule) {
                    throw BookingTransitionException::outsideSchedule($reasons);
                }

                $booking = new PhotographyBooking([
                    'group_size' => $groupSize,
                    'notes' => $notes,
                    'custom_theme_text' => $customThemeText,
                    'wants_prints' => false,
                ]);
                $booking->forceFill([
                    'reference_number' => $this->references->next(ReferenceNumberService::BOOKING),
                    'customer_id' => $customer->getKey(),
                    'contact_number' => $contactNumber,
                    'photography_package_id' => $package->getKey(),
                    'theme_id' => $theme?->getKey(),
                    'snapshot' => $this->bookings->snapshot($package, $theme, $customThemeText),
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'requested_starts_at' => $start,
                    'status' => BookingStatus::Confirmed,
                    'source' => RecordSource::WalkIn,
                    'counter_token' => $counterToken,
                    'estimated_price' => $this->bookings->estimate($package),
                    'confirmed_price' => $price,
                ])->save();

                $note = 'Created at the counter (walk-in) and confirmed by the studio.'
                    .($price !== null ? ' Price '.Money::format($price).'.' : ' The price is set later.')
                    .($reasons !== [] ? ' Confirmed outside the usual schedule: '.implode(' ', $reasons) : '');
                $this->transitions->recordCounterBooking($booking, $owner, $note);

                return ['booking' => $booking->refresh(), 'created' => true];
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $this->byToken($counterToken)) {
                return ['booking' => $existing, 'created' => false];
            }

            throw $e;
        }
    }

    private function price(?string $price): ?string
    {
        if ($price === null || trim($price) === '') {
            return null;
        }

        if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', trim($price))) {
            $this->fail('confirmed_price', 'Type the price as a number, for example 1500 or 1500.50, or leave it empty.');
        }

        return Money::fromCents(Money::toCents(trim($price)));
    }

    private function byToken(string $token): ?PhotographyBooking
    {
        return PhotographyBooking::query()->where('counter_token', $token)->first();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
