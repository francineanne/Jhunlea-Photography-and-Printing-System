<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\NotificationKind;
use App\Enums\PrintingOrderStatus;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Support\LocalTime;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 10K: what happens by itself and must be told, because nobody clicked anything and a walk-in is never notified:
 * - a request's hold ran out without the owner's answer (owner and online customer);
 * - an offered time's hold ran out without the customer's answer (owner and online customer);
 * - a downpayment is due within 24 hours (owner, with a walk-in's phone shown, and online customer);
 * - an order ready for pickup and unpaid may now be closed as Unclaimed (owner);
 * - a photoshoot is tomorrow (from 6:00 PM Manila the day before; owner and online customer).
 * The downpayment expiry itself (a real status change) is told by NotificationDispatcher when it is written.
 *
 * Read only apart from the notices. Run by `notifications:system-events` (every 15 minutes) and lazily with the
 * downpayment expiry on page loads (at most every 5 minutes). One runner at a time (a cache lock); every notice is sent
 * ONCE per record and occurrence (NotificationDispatcher::system()), so two runs never send a second copy.
 */
class SystemEvents
{
    public const LOCK = 'system-events:lock';

    public const RECENT = 'system-events:recent';

    /** The evening before a shoot when its reminder is sent (Manila). */
    public const TOMORROW_FROM_HOUR = 18;

    public function __construct(
        private readonly NotificationDispatcher $notices,
        private readonly ShopSettings $settings,
    ) {}

    /** @return array{holds: int, proposals: int, due_soon: int, unclaimable: int, tomorrow: int}|null null when another runner holds the lock */
    public function run(): ?array
    {
        $sent = Cache::lock(self::LOCK, 120)->get(fn () => [
            'holds' => $this->holds(BookingStatus::Requested),
            'proposals' => $this->holds(BookingStatus::Proposed),
            'due_soon' => $this->dueSoon(),
            'unclaimable' => $this->unclaimable(),
            'tomorrow' => $this->tomorrow(),
        ]);

        return is_array($sent) ? $sent : null; // false: the lock is taken
    }

    /** The lazy run from page loads: at most every 5 minutes, quietly (a failure is logged; the scheduler tries again). */
    public function runIfDue(): void
    {
        if (! Cache::add(self::RECENT, true, 300)) {
            return;
        }

        try {
            $this->run();
        } catch (Throwable $e) {
            Log::warning('System notifications could not be checked.', ['error' => $e::class]);
        }
    }

    /** A request (or an offered time) whose hold ran out while its time is still ahead. */
    private function holds(BookingStatus $status): int
    {
        $now = Carbon::now('UTC');
        $hours = (int) BookingSetting::current()->request_hold_hours;
        $sent = 0;

        $bookings = PhotographyBooking::query()->where('status', $status)
            ->whereNotNull('hold_expires_at')->where('hold_expires_at', '<=', $now)
            // Older ones were already told, or ran out long ago (a week back is enough for every runner to catch up)
            ->where('hold_expires_at', '>', $now->copy()->subDays(7))
            ->orderBy('hold_expires_at')->limit(200)->get();

        foreach ($bookings as $booking) {
            if ($booking->heldStart()->lte($now)) {
                continue; // its time passed: it reads "Expired", which the lists already show
            }

            $occurrence = 'hold:'.$booking->hold_expires_at->toIso8601ZuluString();
            [$owner, $customer] = $status === BookingStatus::Proposed
                ? [NotificationKind::SystemProposalExpired, NotificationKind::ProposalExpired]
                : [NotificationKind::SystemHoldExpired, NotificationKind::HoldExpired];

            $sent += (int) $this->notices->system($owner, $booking, $occurrence, ['hours' => $hours]);
            $this->notices->system($customer, $booking, $occurrence);
        }

        return $sent;
    }

    /** A downpayment due within the next 24 hours: one reminder per deadline (an extended deadline is reminded again). */
    private function dueSoon(): int
    {
        $now = Carbon::now('UTC');
        $sent = 0;

        $bookings = PhotographyBooking::query()->where('status', BookingStatus::AwaitingDownpayment)
            ->whereBetween('downpayment_due_at', [$now, $now->copy()->addHours(24)])
            ->orderBy('downpayment_due_at')->limit(200)->get();

        foreach ($bookings as $booking) {
            $due = $booking->downpayment_due_at->toIso8601ZuluString();
            $sent += (int) $this->notices->system(NotificationKind::SystemDownpaymentDueSoon, $booking, 'due:'.$due, ['due' => $due]);
            $this->notices->system(NotificationKind::DownpaymentDueSoon, $booking, 'due:'.$due, ['due' => $due]);
        }

        return $sent;
    }

    /** Ready for pickup, unpaid, and waiting at least "Unclaimed after days": the owner may close it (once per readiness). */
    private function unclaimable(): int
    {
        $after = (int) $this->settings->paymentRule('unclaimed_after_days');

        if ($after < 1) {
            return 0;
        }

        $today = LocalTime::in(Carbon::now('UTC'))->startOfDay();
        $sent = 0;

        $orders = PrintingOrder::query()->where('status', PrintingOrderStatus::ReadyForPickup)
            ->with(['payments', 'statusHistories' => fn ($query) => $query->where('to_status', PrintingOrderStatus::ReadyForPickup)->reorder('id', 'desc')])
            ->limit(500)->get();

        foreach ($orders as $order) {
            $ready = $order->statusHistories->first();

            if ($ready === null || $order->paymentStatus()->isSettled()) {
                continue;
            }

            $days = (int) LocalTime::in($ready->created_at)->startOfDay()->diffInDays($today);

            if ($days >= $after) {
                $sent += (int) $this->notices->system(NotificationKind::SystemOrderUnclaimable, $order, 'ready:'.$ready->id, ['days' => $days]);
            }
        }

        return $sent;
    }

    /** From 6:00 PM Manila: tomorrow's confirmed photoshoots, one reminder each. */
    private function tomorrow(): int
    {
        $local = LocalTime::in(Carbon::now('UTC'));

        if ($local->hour < self::TOMORROW_FROM_HOUR) {
            return 0;
        }

        $day = $local->copy()->addDay()->startOfDay();
        $sent = 0;

        $bookings = PhotographyBooking::query()->where('status', BookingStatus::Confirmed)
            ->whereBetween('starts_at', [$day->copy()->utc(), $day->copy()->endOfDay()->utc()])
            ->orderBy('starts_at')->limit(200)->get();

        foreach ($bookings as $booking) {
            $occurrence = 'tomorrow:'.$day->format('Y-m-d');
            $sent += (int) $this->notices->system(NotificationKind::SystemShootTomorrow, $booking, $occurrence);
            $this->notices->system(NotificationKind::ShootTomorrow, $booking, $occurrence);
        }

        return $sent;
    }
}
