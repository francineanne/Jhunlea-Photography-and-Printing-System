<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The owner's schedule settings: one row, id 1. Until the owner saves their own, the defaults from config/booking.php
 * are used, so the schedule works on a fresh database. lock_version is not a setting: it is bumped to take the
 * schedule lock (BookingTransitionService) and is never filled from a request.
 */
#[Fillable(['buffer_minutes', 'min_notice_days', 'max_advance_days', 'request_hold_hours', 'max_sessions_per_day'])]
class BookingSetting extends Model
{
    public const ROW_ID = 1;

    // One studio (Phase 10A): never fillable, always 1. The column stays for older rows
    protected $attributes = ['concurrent_sessions' => 1];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'buffer_minutes' => 'integer',
            'min_notice_days' => 'integer',
            'max_advance_days' => 'integer',
            'concurrent_sessions' => 'integer',
            'request_hold_hours' => 'integer',
            'max_sessions_per_day' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /** How early and how far ahead a session can be requested, in the words the booking wizard shows. */
    public function windowText(): string
    {
        $days = $this->max_advance_days;

        return $this->noticeSentence().' You can book up to '.$days.' '.($days === 1 ? 'day' : 'days').' ahead.';
    }

    /** Phase 10I: the minimum notice in whole days, never below 1 (same-day photoshoots are never allowed). */
    public function noticeDays(): int
    {
        return max(1, (int) ($this->min_notice_days ?? config('booking.defaults.min_notice_days')));
    }

    /**
     * Phase 10I, THE notice rule: the earliest moment a photoshoot may start, as UTC. It is 00:00 in Manila of
     * (today + the notice in days), today read from the app clock in Manila. Online requests, the studio's proposals
     * and walk-ins at the counter all use it; nothing may start on a day before it.
     */
    public function earliestStart(?CarbonInterface $now = null): Carbon
    {
        $zone = config('app.display_timezone');

        return Carbon::instance($now ?? Carbon::now())->setTimezone($zone)->startOfDay()->addDays($this->noticeDays())->utc();
    }

    /** Why a day before the earliest one cannot be booked (the wizard, the counter and the server's refusal). */
    public function noticeReason(): string
    {
        $days = $this->noticeDays();

        return $days === 1 ? 'Same-day photoshoots are not allowed. Choose tomorrow or later.' : "Book at least {$days} days ahead.";
    }

    /** The one sentence customers read about the notice (booking page, FAQ, terms). */
    public function noticeSentence(): string
    {
        $days = $this->noticeDays();

        return 'Photoshoots must be booked at least '.$days.' '.($days === 1 ? 'day' : 'days').' ahead; same-day photoshoots are not allowed.';
    }

    /** Until when a request placed (or a time proposed) at this moment holds its time (Phase 10A). */
    public function holdUntil(?CarbonInterface $from = null): Carbon
    {
        return Carbon::instance($from ?? now())->utc()->addHours(max(1, (int) $this->request_hold_hours));
    }

    /** The saved settings, or the config defaults when the owner has not saved any (not written to the database). */
    public static function current(): self
    {
        $settings = static::query()->find(self::ROW_ID) ?? (new static)->forceFill(config('booking.defaults') + ['id' => self::ROW_ID]);

        // One studio (Phase 10A): whatever an old row holds, one session runs at a time
        $settings->concurrent_sessions = 1;
        $settings->request_hold_hours ??= (int) config('booking.defaults.request_hold_hours');

        return $settings;
    }
}
