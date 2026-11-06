<?php

namespace App\Enums;

/** Where a printing order or photography booking came from (Phase 8A). Set only by server code, never by a request. */
enum RecordSource: string
{
    case Online = 'online';
    case WalkIn = 'walk_in';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::WalkIn => 'Walk-in',
        };
    }
}
