<?php

namespace App\Services\Assistant;

use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Services\Chat\OwnerPresence;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;

/**
 * The facts that change by the minute (Phase 7E-2a): today's date, weekday and time in Manila, whether the shop is
 * open now and when it next opens. Computed by code from the existing hours and blocked dates (the same rules as
 * OwnerPresence), so the model never works out a date or an opening itself. Read once per request at most.
 */
class LiveFacts
{
    public const DATE_FORMAT = 'l, F j, Y';

    public function __construct(private readonly OwnerPresence $presence, private readonly ShopSettings $shop) {}

    public function now(): Carbon
    {
        return Carbon::now(config('app.display_timezone'));
    }

    /** The prompt line. It belongs in the volatile tail of the system text: it changes every minute. */
    public function line(): string
    {
        $now = $this->now();

        return 'NOW (Philippine time, computed by the system): '.$now->format(self::DATE_FORMAT).', '.$now->format('g:i A').'. '.$this->openText().
            ' Any date before today has already passed.';
    }

    /** "The shop is open now, until 6:00 PM.", "The shop is closed now; it next opens tomorrow at 9:00 AM." and the like. */
    public function openText(): string
    {
        // Phase 10A: the weekly rules are the PHOTOSHOOT hours. Once the owner has typed the shop's own opening hours
        // (a text the system cannot work out "open now" from), the shop hours are given as written and the computed
        // part speaks about photoshoots only
        if (($hours = $this->shop->openingHours()) !== null) {
            return "The shop's opening hours, as the owner wrote them: {$hours}. ".$this->photoshootText();
        }

        if ($this->presence->shopOpenNow()) {
            $closes = $this->closesToday();

            return 'The shop is open now'.($closes === null ? '.' : ", until {$closes}.");
        }

        $next = $this->presence->nextOpeningText();
        $why = $this->closedToday() ? 'The shop is closed today' : 'The shop is closed now';

        return $next === null ? "{$why}; no opening is set in the next 7 days." : "{$why}; it next opens {$next}.";
    }

    /** "It is inside the photoshoot hours now, until 5:00 PM." or when they next start, from the weekly rules. */
    public function photoshootText(): string
    {
        if ($this->presence->shopOpenNow()) {
            $closes = $this->closesToday();

            return 'It is inside the photoshoot hours now'.($closes === null ? '.' : ", until {$closes}.");
        }

        $next = $this->presence->nextOpeningText();

        return $next === null
            ? 'It is outside the photoshoot hours now; none are set in the next 7 days.'
            : "It is outside the photoshoot hours now; they next start {$next}.";
    }

    public function isOpenNow(): bool
    {
        return $this->presence->shopOpenNow();
    }

    /** The same as openText(), in Filipino, for a customer who wrote in Filipino (CodeAnswers). */
    public function openTextFilipino(): string
    {
        if ($this->presence->shopOpenNow()) {
            $closes = $this->closesToday();

            return 'Bukas po kami ngayon'.($closes === null ? '.' : " hanggang {$closes}.");
        }

        $next = $this->presence->nextOpening();
        $why = $this->closedToday() ? 'Sarado po kami ngayong araw' : 'Sarado na po kami ngayon';

        if ($next === null) {
            return "{$why}.";
        }

        $day = match ($next['offset']) {
            0 => 'mamaya',
            1 => 'bukas',
            default => 'sa '.self::FILIPINO_DAYS[$next['at']->dayOfWeek],
        };

        return "{$why}; magbubukas kami {$day} ng {$next['at']->format('g:i A')}.";
    }

    public const FILIPINO_DAYS = ['Linggo', 'Lunes', 'Martes', 'Miyerkules', 'Huwebes', 'Biyernes', 'Sabado'];

    /** True on a weekday with no hours or a blocked date (a day off), not merely after closing time. */
    public function closedToday(): bool
    {
        $now = $this->now();
        $rule = AvailabilityRule::week()[$now->dayOfWeek];

        return ! $rule->is_open || ! $rule->opens_at || ! $rule->closes_at
            || BlockedDate::query()->whereDate('date', $now->toDateString())->exists();
    }

    public function closesToday(): ?string
    {
        $rule = AvailabilityRule::week()[$this->now()->dayOfWeek];

        return $rule->closes_at ? Carbon::createFromFormat('H:i', substr($rule->closes_at, 0, 5))->format('g:i A') : null;
    }
}
