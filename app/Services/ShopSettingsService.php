<?php

namespace App\Services;

use App\Models\ShopSetting;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\DB;

/**
 * Writes the single shop_settings row (id 1). The row may not exist yet (until the owner saves, nothing is stored and
 * the defaults are used): it is created with insertOrIgnore, so two saves at once can never create it twice, then
 * locked and updated. Saving the same values twice changes nothing twice. Only the columns of the section that was
 * saved are written, and only the columns named here: never the id or the timestamps from a request.
 */
class ShopSettingsService
{
    public function __construct(private readonly ShopSettings $settings) {}

    /** @param  array{shop_name: ?string, address: ?string, phone: ?string, email: ?string}  $info */
    public function saveBusiness(array $info): void
    {
        $this->write(array_intersect_key($info, array_flip(ShopSettings::BUSINESS)));
    }

    /** @param  array<string, int>  $limits  the six upload limits, keyed like ShopSettings::LIMITS */
    public function saveLimits(array $limits): void
    {
        $this->write(array_map('intval', array_intersect_key($limits, ShopSettings::LIMITS)));
    }

    /** Back to the starting defaults in config: every stored limit is cleared. The business info is kept. */
    public function resetLimits(): void
    {
        $this->write(array_fill_keys(array_keys(ShopSettings::LIMITS), null));
    }

    /** @param  array<string, mixed>  $values */
    private function write(array $values): void
    {
        DB::transaction(function () use ($values) {
            DB::table('shop_settings')->insertOrIgnore(['id' => ShopSetting::ROW_ID, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('shop_settings')->where('id', ShopSetting::ROW_ID)->lockForUpdate()->first();
            DB::table('shop_settings')->where('id', ShopSetting::ROW_ID)->update($values + ['updated_at' => now()]);
        });

        $this->settings->forget();
    }

    /**
     * Runs the callback while holding the settings row lock: the one lock that keeps the FAQ positions consecutive
     * (FaqService). The row is created first when it does not exist, so there is always a row to lock.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function locked(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            DB::table('shop_settings')->insertOrIgnore(['id' => ShopSetting::ROW_ID, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('shop_settings')->where('id', ShopSetting::ROW_ID)->lockForUpdate()->first();

            return $callback();
        });
    }
}
