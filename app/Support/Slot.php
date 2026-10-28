<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** One free session time. The moments are UTC; the text is in the shop's time zone (LocalTime). */
final readonly class Slot
{
    public function __construct(
        public Carbon $startsAt,
        public Carbon $endsAt,
    ) {}

    /** "9:00 AM – 10:00 AM" in shop local time. */
    public function timeText(): string
    {
        return LocalTime::format($this->startsAt, 'g:i A').' – '.LocalTime::format($this->endsAt, 'g:i A');
    }

    /** "Mon, Nov 3, 2026" in shop local time. */
    public function dateText(): string
    {
        return LocalTime::format($this->startsAt, 'D, M j, Y');
    }

    /** The calendar day the slot is on, in shop local time, as Y-m-d. */
    public function localDate(): string
    {
        return LocalTime::format($this->startsAt, 'Y-m-d');
    }
}
