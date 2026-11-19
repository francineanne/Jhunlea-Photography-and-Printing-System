<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\PhotographyBooking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancels the bookings whose downpayment was not paid by the deadline (Phase 10B). ONE method does it, run two ways:
 *  - by the scheduler, every 15 minutes (`php artisan bookings:expire-unpaid`, routes/console.php);
 *  - lazily, at the start of the pages that show bookings or free times (runIfDue()), so it also works on hosting
 *    without cron.
 * Each booking is cancelled in its own transaction by BookingTransitionService::expire(), which locks the booking row
 * and reads it again: a payment that committed first wins (the booking is then Confirmed and is left alone), running it
 * twice cancels nothing twice, and a booking already cancelled is skipped. Before this runs, the booking already stops
 * holding its time at the deadline (PhotographyBooking::scopeHoldingTime).
 */
class DownpaymentExpiryService
{
    // How many bookings one run cancels at most; the next run takes the rest
    public const BATCH = 100;

    public function __construct(private readonly BookingTransitionService $transitions) {}

    /** @return int how many bookings were cancelled */
    public function expireDue(): int
    {
        $ids = PhotographyBooking::query()
            ->where('status', BookingStatus::AwaitingDownpayment)
            ->where('downpayment_due_at', '<=', Carbon::now('UTC'))
            ->orderBy('downpayment_due_at')
            ->limit(self::BATCH)
            ->pluck('id');

        $cancelled = 0;

        foreach ($ids as $id) {
            if ($this->transitions->expire((new PhotographyBooking)->forceFill(['id' => $id]))) {
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * The lazy run: at most once per request, and quietly: a failure is logged and the page carries on (the scheduler
     * tries again). It is called at the START of a page or lookup (controllers, LazyExpiry middleware), never inside
     * another action's transaction.
     */
    public function runIfDue(): void
    {
        // Once per request: the flag lives on the request itself (a test may send several requests to one app)
        $request = app()->bound('request') ? request() : null;

        if ($request?->attributes->get(self::class)) {
            return;
        }

        $request?->attributes->set(self::class, true);

        try {
            $this->expireDue();
        } catch (Throwable $e) {
            Log::warning('Expired downpayments could not be processed.', ['error' => $e::class]);
        }

        // Phase 10K: the automatic-event notices ride along (at most every 5 minutes, quietly; the scheduler also runs them)
        if (config('notifications.lazy_system_events')) {
            app(SystemEvents::class)->runIfDue();
        }
    }
}
