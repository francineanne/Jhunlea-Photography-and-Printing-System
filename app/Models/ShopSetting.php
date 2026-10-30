<?php

namespace App\Models;

use App\Enums\OwnerPresenceMode;
use App\Support\ShopSettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The owner's business info and upload limits: one row, id 1, written only by ShopSettingsService. Never read
 * directly: App\Support\ShopSettings is the one class that reads it (with the config defaults and the server's caps).
 */
#[Fillable(['shop_name', 'address', 'phone', 'email', 'printing_max_file_mb', 'printing_max_total_mb', 'printing_max_files', 'booking_max_image_mb', 'booking_max_images', 'booking_max_total_mb'])]
class ShopSetting extends Model
{
    public const ROW_ID = 1;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'printing_max_file_mb' => 'integer',
            'printing_max_total_mb' => 'integer',
            'printing_max_files' => 'integer',
            'booking_max_image_mb' => 'integer',
            'booking_max_images' => 'integer',
            'booking_max_total_mb' => 'integer',
            'owner_presence' => OwnerPresenceMode::class,
            'owner_last_active_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The settings of this request are read once; a change made through the model is seen at once
        static::saved(fn () => app(ShopSettings::class)->forget());
        static::deleted(fn () => app(ShopSettings::class)->forget());
    }
}
