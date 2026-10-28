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
     *
     * Only the path and the query are ever returned, never the address as stored: whatever the session holds, the
     * redirect stays on this site. Anything unusual (another host, a user name in the address, another scheme,
     * backslashes, encoded slashes, dot segments, control characters) goes home.
     */
    public function afterLogin(?string $intended): string
    {
        $parts = is_string($intended) && $intended !== '' ? parse_url($intended) : false;

        if ($parts === false || preg_match('/[\x00-\x20\x7F\\\\]/', (string) $intended) === 1) {
            return $this->home();
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (($scheme !== '' && ! in_array($scheme, ['http', 'https'], true))
            || isset($parts['user']) || isset($parts['pass'])
            || ($host !== '' && $host !== strtolower((string) parse_url(url('/'), PHP_URL_HOST)))
            || ($host === '' && $scheme !== '')) {
            return $this->home();
        }

        $path = $parts['path'] ?? '';
        $area = match ($this) {
            self::Owner => '/owner',
            self::Customer => '/my',
        };

        $plain = preg_match('#^/[A-Za-z0-9/_\-.~]*$#', $path) === 1
            && ! str_contains($path, '//')
            && preg_match('#(^|/)\.\.?(/|$)#', $path) !== 1;

        if (! $plain || ($path !== $area && ! str_starts_with($path, $area.'/'))) {
            return $this->home();
        }

        return isset($parts['query']) && $parts['query'] !== '' ? $path.'?'.$parts['query'] : $path;
    }
}
