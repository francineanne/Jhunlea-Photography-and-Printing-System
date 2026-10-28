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

    /**
     * Where to go after signing in: the page the visitor was sent away from (Laravel keeps it as the "intended" address
     * when a guest opens a page that needs login), but only when it is inside this role's own area, so a customer's
     * link never lands the owner on a 403 and the other way round. Anything else goes home.
     */
    public function afterLogin(?string $intended): string
    {
        if ($intended === null || parse_url($intended, PHP_URL_HOST) !== parse_url(url('/'), PHP_URL_HOST)) {
            return $this->home();
        }

        $path = (string) parse_url($intended, PHP_URL_PATH);
        $area = match ($this) {
            self::Owner => '/owner',
            self::Customer => '/my',
        };

        return $path === $area || str_starts_with($path, $area.'/') ? $intended : $this->home();
    }
}
