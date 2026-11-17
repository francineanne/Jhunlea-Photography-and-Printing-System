<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\ShopSettings;
use Illuminate\View\View;

/**
 * Phase 10C: the short Terms and Privacy page that the registration checkbox links to. Plain words about what the
 * system really keeps, why, who sees it, how long and how to ask for deletion (Data Privacy Act of 2012, RA 10173).
 * The shop's contact details come from Settings, only when they are set.
 */
class TermsController extends Controller
{
    public function __invoke(ShopSettings $settings): View
    {
        return view('public.terms', [
            'shop' => $settings->business(),
            'downpaymentPercent' => $settings->downpaymentPercent(),
            'auditDays' => (int) config('audit.retention_days'),
        ]);
    }
}
