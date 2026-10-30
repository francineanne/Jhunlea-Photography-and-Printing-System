<?php

namespace App\Services\Chat;

use App\Enums\OwnerPresenceMode;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\ShopSetting;
use App\Services\ShopSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Whether the owner counts as away in the studio chat, so Lumi may answer there (Phase 7B). "away" and "available" are
 * the owner's own choice; "auto" counts the owner as away after config chat.presence.active_minutes without opening
 * or polling the inbox or a thread, or replying. The opening hours never decide it: they only word the away notice.
 */
class OwnerPresence
{
    public function __construct(private readonly ShopSettingsService $writer) {}

    public function mode(): OwnerPresenceMode
    {
        return OwnerPresenceMode::tryFrom((string) ($this->row()?->owner_presence ?? '')) ?? OwnerPresenceMode::Auto;
    }

    public function setMode(OwnerPresenceMode $mode): void
    {
        $this->writer->savePresence($mode);
    }

    /**
     * The owner is active right now. Written straight to the row (never through the per-request ShopSettings copy) and
     * at most once per config chat.presence.touch_interval_seconds, so polling does not write on every request.
     */
    public function touch(): void
    {
        $now = now();
        $since = $now->copy()->subSeconds((int) config('chat.presence.touch_interval_seconds'));

        $updated = DB::table('shop_settings')->where('id', ShopSetting::ROW_ID)
            ->where(fn ($query) => $query->whereNull('owner_last_active_at')->orWhere('owner_last_active_at', '<', $since))
            ->update(['owner_last_active_at' => $now]);

        if ($updated === 0) {
            DB::table('shop_settings')->insertOrIgnore(['id' => ShopSetting::ROW_ID, 'owner_last_active_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function lastActiveAt(): ?Carbon
    {
        $value = $this->row()?->owner_last_active_at;

        return $value === null ? null : Carbon::parse($value, 'UTC');
    }

    public function isAway(): bool
    {
        $row = $this->row();
        $mode = OwnerPresenceMode::tryFrom((string) ($row?->owner_presence ?? '')) ?? OwnerPresenceMode::Auto;

        if ($mode !== OwnerPresenceMode::Auto) {
            return $mode === OwnerPresenceMode::Away;
        }

        $active = $row?->owner_last_active_at === null ? null : Carbon::parse($row->owner_last_active_at, 'UTC');

        return $active === null || $active->lessThan(now()->subMinutes((int) config('chat.presence.active_minutes')));
    }

    /**
     * The two presence columns, read fresh on every call (one query): never from the per-request ShopSettings copy,
     * which may predate a touch or a mode change made in the same request.
     */
    private function row(): ?object
    {
        return DB::table('shop_settings')->where('id', ShopSetting::ROW_ID)->first(['owner_presence', 'owner_last_active_at']);
    }

    /**
     * When the studio next opens, in words ("today at 9:00 AM", "tomorrow at 9:00 AM", "Monday at 9:00 AM"), from the
     * weekly hours and the blocked dates in Manila time; null when nothing opens in the next 7 days.
     */
    public function nextOpeningText(): ?string
    {
        $zone = config('app.display_timezone');
        $now = Carbon::now($zone);
        $week = AvailabilityRule::week();
        $blocked = BlockedDate::query()->whereBetween('date', [$now->toDateString(), $now->copy()->addDays(7)->toDateString()])
            ->pluck('date')->map(fn ($date) => Carbon::parse($date)->toDateString())->all();

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $now->copy()->startOfDay()->addDays($offset);
            $rule = $week[$day->dayOfWeek];

            if (! $rule->is_open || ! $rule->opens_at || in_array($day->toDateString(), $blocked, true)) {
                continue;
            }

            $opens = Carbon::parse($day->toDateString().' '.substr($rule->opens_at, 0, 5), $zone);

            if ($opens->lessThanOrEqualTo($now)) {
                continue;
            }

            $when = match ($offset) {
                0 => 'today',
                1 => 'tomorrow',
                default => $opens->format('l'),
            };

            return $when.' at '.$opens->format('g:i A');
        }

        return null;
    }

    /** The notice shown while the owner is away: with the next opening when known, or the AI-off wording. */
    public function awayNotice(): string
    {
        if (! config('assistant.enabled')) {
            return config('chat.messages.owner_unavailable_ai_off');
        }

        $opens = $this->nextOpeningText();

        return $opens === null ? config('chat.messages.away_notice_no_time') : str_replace(':opens', $opens, config('chat.messages.away_notice'));
    }
}
