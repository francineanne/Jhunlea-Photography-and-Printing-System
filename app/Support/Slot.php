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

    /** The start as a booking form sends it back: the UTC moment, e.g. 2026-11-05T02:00:00Z. */
    public function value(): string
    {
        return LocalTime::iso($this->startsAt);
    }

    /** @return array{starts_at: string, date: string, time: string, time_text: string, date_text: string} */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->value(),
            'date' => $this->localDate(),
            'time' => LocalTime::format($this->startsAt, 'g:i A'),
            'time_text' => $this->timeText(),
            'date_text' => $this->dateText(),
        ];
    }
}
