<?php

namespace App\Support;

/**
 * Phase 10E: whether Lumi may answer at all. Both must allow it: the server switch (AI_ENABLED in .env, for the
 * developer) and the owner's switch (Settings, Lumi assistant). Every place that used to read config('assistant.enabled')
 * asks here.
 */
class Lumi
{
    public static function on(): bool
    {
        return (bool) config('assistant.enabled') && self::allowedByOwner();
    }

    public static function allowedByOwner(): bool
    {
        return rescue(fn () => app(ShopSettings::class)->lumiEnabled(), true, false);
    }

    /** The owner turned it off (the server switch may be on): the owner's own wording applies. */
    public static function offInSettings(): bool
    {
        return ! self::allowedByOwner();
    }

    /** The text customers see in Ask Lumi when Lumi cannot answer: the owner's own, else the starting text. */
    public static function fallbackText(): string
    {
        return rescue(fn () => app(ShopSettings::class)->lumiFallbackText(), null, false) ?? (string) config('assistant.messages.unavailable');
    }
}
