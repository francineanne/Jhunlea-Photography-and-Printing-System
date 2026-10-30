<?php

namespace App\Enums;

/**
 * A person's appearance choice, stored in users.theme (Phase 6C). Light and Dark are fixed; System follows the device.
 * Signed-in people get it from the server on every page (layouts/app); guests keep theirs in the browser only.
 */
enum ColorScheme: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light',
            self::Dark => 'Dark',
            self::System => 'System',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Light => 'Clean white pages, best in daylight.',
            self::Dark => 'Dark pages that are easier on the eyes at night.',
            self::System => 'Follows the light or dark setting of each device you use.',
        };
    }
}
