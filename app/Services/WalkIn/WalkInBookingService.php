<?php

namespace App\Services\WalkIn;

use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
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
use App\Services\PaymentService;
use App\Services\ReferenceNumberService;
use App\Services\ScheduleConflictService;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\PaymentRules;
use App\Support\PhilippineMobile;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A walk-in photography booking made by the owner at the counter (Phase 8A; the rules since Phase 10J). It is created
 * (source walk_in) with its first history row, in one transaction under the same schedule lock as
 * BookingTransitionService::confirm(), so it holds that time for online customers exactly like any other booking.
 *
 * Phase 10J: a walk-in booking follows EXACTLY the online rules, with no override at the counter: the minimum notice in
 * days (10I), how far ahead one may book, the photoshoot hours, blocked dates, the buffer, the daily limit and the
 * people of the package; a clash or a passed time is never allowed. "Confirm anyway" and "Over the daily limit" are
 * gone for walk-ins: when a rule blocks the time the owner picks another one.
 *
 * The price is REQUIRED (the owner is at the counter and knows it). The downpayment follows the online rules too
 * (PaymentRules): the owner records the downpayment or the full price now (it is then Confirmed in this transaction),
 * or ticks "Waive downpayment" with a reason (audited; Confirmed), or saves it "Awaiting downpayment" with the normal
 * deadline (PaymentRules::dueAt), which is extended, waived or cancelled by the system at the deadline like any
 * online booking. A price of ₱0.00 or a 0% rule needs no downpayment. The walk-in is never notified: the walk-in
 * page shows the deadline and the phone so the owner can call. LOCK ORDER: the caller (WalkInController) takes the
 * schedule lock, then the PAY counter, before it creates a new walk-in customer.
 *
 * The counter token makes a double click save one booking.
 */
class WalkInBookingService
{
    public const PRICE_REQUIRED = 'Enter the price of this session. You are at the counter, so the price is set now.';

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
        string $counterToken,
        ?PaymentKind $payKind = null,
        ?PaymentMethod $payMethod = null,
        ?string $gcashReference = null,
        ?string $waiveReason = null,
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

        if (! BookingService::groupSizeFits($package, $groupSize)) {
            $this->fail('group_size', BookingService::groupSizeMessage($package));
        }

        if (! BookingService::isContactNumber($contactNumber)) {
            $this->fail('contact_number', BookingService::CONTACT_NUMBER_MESSAGE);
        }

        $contactNumber = (string) PhilippineMobile::normalize($contactNumber);

        $notes = $notes === null || trim($notes) === '' ? null : trim($notes);

        if ($notes !== null && mb_strlen($notes) > BookingService::NOTES_MAX) {
            $this->fail('notes', BookingService::NOTES_MESSAGE);
        }

        $price = $this->price($confirmedPrice);
        $waiveReason = $waiveReason === null || trim($waiveReason) === '' ? null : trim($waiveReason);
        $this->checkPayment($price, $payKind, $payMethod, $waiveReason);
        $rules = app(PaymentRules::class);
        $start = Carbon::instance($startsAt)->utc();
        $end = $start->copy()->addMinutes($package->duration_minutes);

        try {
            return DB::transaction(function () use ($owner, $customer, $package, $theme, $customThemeText, $start, $end, $groupSize, $contactNumber, $notes, $price, $counterToken, $payKind, $payMethod, $gcashReference, $waiveReason, $rules) {
                $this->transitions->lockSchedule();

                if (! $this->conflicts->isFree($start, $end)) {
                    throw BookingTransitionException::conflict();
                }

                if ($start->lte(Carbon::now('UTC'))) {
                    throw BookingTransitionException::timePassed();
                }

                // Phase 10I: the same minimum notice as online; "Confirm anyway" and "Over the daily limit" never set it aside
                if ($refusal = $this->availability->noticeRefusal($start)) {
                    throw BookingTransitionException::tooSoon($refusal);
                }

                // Phase 10J: the online schedule rules, no override (hours, blocked days, how far ahead)
                $reasons = $this->availability->ruleViolations($package->duration_minutes, $start);

                if ($reasons !== []) {
                    throw BookingTransitionException::outsideScheduleNoOverride($reasons);
                }

                if ($this->availability->dayIsFull($start)) {
                    throw BookingTransitionException::dailyLimitNoOverride(AvailabilityService::dailyLimitReason());
                }

                // The online wizard's own question last (the slot grid and the buffer around other bookings)
                if (! $this->availability->isAvailable($package->duration_minutes, $start)) {
                    throw BookingTransitionException::slotNotAvailable();
                }

                // Paid now: it waits for the payment recorded below, which confirms it. Otherwise the online rule:
                // Awaiting downpayment with the normal deadline, unless no downpayment is needed or it is waived
                $awaits = $payKind === null && $waiveReason === null && $rules->needsDownpayment($price);
                $status = match (true) {
                    $payKind !== null, $awaits => BookingStatus::AwaitingDownpayment,
                    default => BookingStatus::Confirmed,
                };
                $due = match (true) {
                    $payKind !== null => $start,
                    $awaits => $rules->dueAt($start, Carbon::now('UTC')),
                    default => null,
                };

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
                    // With a payment it starts waiting for it and the payment below confirms it in this transaction
                    'status' => $status,
                    'downpayment_due_at' => $due,
                    'source' => RecordSource::WalkIn,
                    'counter_token' => $counterToken,
                    'estimated_price' => $this->bookings->estimate($package),
                    'confirmed_price' => $price,
                ])->save();

                $note = 'Created at the counter (walk-in). Price '.Money::format($price).'.'
                    .($awaits ? ' Downpayment of '.Money::cents($rules->downpaymentCents($price)).' due by '.LocalTime::format($due).'.' : '')
                    .($waiveReason !== null ? ' Downpayment waived: '.$waiveReason : '');
                $this->transitions->recordCounterBooking($booking, $owner, $note);

                if ($payKind !== null) {
                    app(PaymentService::class)->record($booking, $owner, $payMethod, $gcashReference, null, $payKind);
                } elseif ($waiveReason !== null) {
                    $this->transitions->recordCounterWaiver($booking, $owner, $waiveReason);
                }

                return ['booking' => $booking->refresh(), 'created' => true];
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $this->byToken($counterToken)) {
                return ['booking' => $existing, 'created' => false];
            }

            throw $e;
        }
    }

    /**
     * Phase 10J: nothing paid now is allowed (it then waits for its downpayment like an online booking); a payment needs
     * how it was paid; a free booking needs neither a payment nor a waiver.
     */
    private function checkPayment(string $price, ?PaymentKind $kind, ?PaymentMethod $method, ?string $waiveReason): void
    {
        if (Money::toCents($price) === 0) {
            return;
        }

        if ($kind === PaymentKind::Balance) {
            $this->fail('pay_kind', 'Choose the downpayment or the full price.');
        }

        if ($kind !== null && $method === null) {
            $this->fail('pay_method', 'Choose how the customer paid: cash or GCash.');
        }
    }

    /** Phase 10J: the price is required at the counter (₱0.00 allowed for a free session). */
    private function price(?string $price): string
    {
        if ($price === null || trim($price) === '') {
            $this->fail('confirmed_price', self::PRICE_REQUIRED);
        }

        if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', trim($price))) {
            $this->fail('confirmed_price', 'Type the price as a number, for example 1500 or 1500.50.');
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
