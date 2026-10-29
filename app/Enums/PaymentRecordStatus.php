<?php

namespace App\Enums;

/**
 * The state of one payment row. A recorded payment counts; a voided one was cancelled by the shop and never counts.
 * A payment row is never deleted.
 */
enum PaymentRecordStatus: string
{
    case Recorded = 'recorded';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Voided => 'Voided',
        };
    }
}
