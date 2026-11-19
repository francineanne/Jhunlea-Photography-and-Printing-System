<?php

namespace App\Console\Commands;

use App\Services\DownpaymentExpiryService;
use App\Services\SystemEvents;
use Illuminate\Console\Command;

/**
 * Phase 10K: tells the owner (and the online customer) about what the system did or noticed by itself: overdue
 * downpayments are cancelled first (bookings:expire-unpaid's own work, which notifies as it writes), then the ran-out
 * holds, the due-soon deadlines, the orders that may be closed as Unclaimed and tomorrow's photoshoots. Scheduled every
 * 15 minutes (routes/console.php); safe to run at any time: every notice is sent once.
 */
class SendSystemNotifications extends Command
{
    protected $signature = 'notifications:system-events';

    protected $description = 'Notify the owner and customers about automatic events (expiries, deadlines, reminders)';

    public function handle(DownpaymentExpiryService $expiry, SystemEvents $events): int
    {
        $cancelled = $expiry->expireDue();
        $sent = $events->run();

        if ($sent === null) {
            $this->warn('Another run is checking right now; nothing was sent twice.');

            return self::SUCCESS;
        }

        $this->info("{$cancelled} cancelled; notices: ".collect($sent)->map(fn ($count, $what) => "{$what} {$count}")->join(', ').'.');

        return self::SUCCESS;
    }
}
