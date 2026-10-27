<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Customer = 'customer';

    /** Where this role lands after login and when an authenticated user opens a guest page. */
    public function home(): string
    {
        return match ($this) {
            self::Owner => '/owner',
            self::Customer => '/my/dashboard',
        };
    }
}
