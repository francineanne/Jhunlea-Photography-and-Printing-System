<?php

namespace Tests\Support;

use App\Support\ShopSettings;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10B: the owner's payment rules for a test. The defaults (a 50% downpayment, printing paid first from ₱300.00)
 * apply unless a test sets its own. Tests written before Phase 10B that prove something else (schedules, conflicts,
 * notifications, the release gate) call withoutDownpaymentsOrPrepayment(), the rules of that time: confirming a
 * photoshoot confirms it at once and every printing order is paid at pickup.
 */
trait SetsPaymentRules
{
    /** @param  array<string, int|string|null>  $values  shop_settings columns */
    protected function paymentRules(array $values): void
    {
        DB::table('shop_settings')->updateOrInsert(['id' => 1], $values + ['updated_at' => now()]);
        DB::table('shop_settings')->where('id', 1)->whereNull('created_at')->update(['created_at' => now()]);
        app(ShopSettings::class)->forget();
    }

    protected function withoutDownpaymentsOrPrepayment(): void
    {
        $this->paymentRules(['downpayment_percent' => 0, 'prepay_from' => '99999999.00']);
    }
}
