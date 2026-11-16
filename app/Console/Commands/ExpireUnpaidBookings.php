<?php

namespace App\Console\Commands;

use App\Services\DownpaymentExpiryService;
use Illuminate\Console\Command;

/**
 * Cancels the bookings whose downpayment was not paid by the deadline (Phase 10B). Registered in the scheduler every
 * 15 minutes (routes/console.php); the same work also runs lazily when the owner or a customer opens a booking page.
 * Safe to run at any time and as often as wanted: it only cancels what is overdue and unpaid.
 */
class ExpireUnpaidBookings extends Command
{
    protected $signature = 'bookings:expire-unpaid';

    protected $description = 'Cancel bookings whose downpayment was not paid by the deadline';

    public function handle(DownpaymentExpiryService $expiry): int
    {
        $count = $expiry->expireDue();
        $this->info($count === 1 ? '1 booking cancelled.' : "{$count} bookings cancelled.");

        return self::SUCCESS;
    }
}
