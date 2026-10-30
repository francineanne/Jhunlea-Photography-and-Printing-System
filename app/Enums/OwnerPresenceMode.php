<?php

namespace App\Enums;

/**
 * How the studio chat decides whether the owner is away (Lumi answers only then). Auto follows the owner's activity,
 * never the opening hours; the hours only word the away notice.
 */
enum OwnerPresenceMode: string
{
    case Available = 'available';
    case Away = 'away';
    case Auto = 'auto';
}
