<?php

namespace App\Enums;

/** Things written to an order's history that do not change its status. A status change has no event. */
enum OrderEvent: string
{
    case InfoRequested = 'info_requested';
    case InfoAnswered = 'info_answered';
    case PriceChanged = 'price_changed';
    case FileRemoved = 'file_removed';

    public function label(): string
    {
        return match ($this) {
            self::InfoRequested => 'More information requested',
            self::InfoAnswered => 'Files added in reply',
            self::PriceChanged => 'Confirmed price changed',
            self::FileRemoved => 'File removed',
        };
    }
}
