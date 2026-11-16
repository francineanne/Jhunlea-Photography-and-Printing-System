<?php

namespace App\Http\Middleware;

use App\Services\DownpaymentExpiryService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 10B: before a page that shows bookings, payments or free times, the bookings whose downpayment deadline passed
 * are cancelled (DownpaymentExpiryService::runIfDue(), once per request, before the page's own work). This keeps the
 * rule working on hosting without cron; the scheduler runs the same method every 15 minutes.
 */
class ExpireUnpaidDownpayments
{
    public function __construct(private readonly DownpaymentExpiryService $expiry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->expiry->runIfDue();

        return $next($request);
    }
}
