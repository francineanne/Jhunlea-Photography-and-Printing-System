<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The owner's schedule settings: one row, id 1. Until the owner saves their own, the defaults from config/booking.php
 * are used, so the schedule works on a fresh database. lock_version is not a setting: it is bumped to take the
 * schedule lock (BookingTransitionService) and is never filled from a request.
 */
#[Fillable(['buffer_minutes', 'min_notice_hours', 'max_advance_days', 'concurrent_sessions'])]
class BookingSetting extends Model
{
    public const ROW_ID = 1;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'buffer_minutes' => 'integer',
            'min_notice_hours' => 'integer',
            'max_advance_days' => 'integer',
            'concurrent_sessions' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /** The saved settings, or the config defaults when the owner has not saved any (not written to the database). */
    public static function current(): self
    {
        return static::query()->find(self::ROW_ID) ?? (new static)->forceFill(config('booking.defaults') + ['id' => self::ROW_ID]);
    }
}
