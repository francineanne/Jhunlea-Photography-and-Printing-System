<?php

namespace App\Services\Assistant\Owner;

use Illuminate\Support\Carbon;

/** A period of the shop's calendar days, both ends included (Y-m-d, Asia/Manila). Null ends mean all time. */
final readonly class DateRange
{
    public function __construct(
        public ?string $from,
        public ?string $to,
        public string $label,
    ) {}

    public function days(): ?int
    {
        return $this->from === null || $this->to === null ? null : (int) Carbon::parse($this->from)->diffInDays(Carbon::parse($this->to)) + 1;
    }

    /** The period of the same length right before this one (for a comparison), or null for all time. */
    public function previous(): ?self
    {
        if ($this->from === null || $this->to === null) {
            return null;
        }

        $days = (int) $this->days();
        $to = Carbon::parse($this->from)->subDay();

        return new self($to->copy()->subDays($days - 1)->toDateString(), $to->toDateString(), 'the '.$days.' days before');
    }
}
