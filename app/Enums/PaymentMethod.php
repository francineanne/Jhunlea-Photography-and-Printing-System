<?php

namespace App\Enums;

/** How a customer paid at the shop. Both are taken face to face; the system only records them. */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Gcash = 'gcash';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Gcash => 'GCash',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cash => 'payments',
            self::Gcash => 'qr_code_2',
        };
    }
}
