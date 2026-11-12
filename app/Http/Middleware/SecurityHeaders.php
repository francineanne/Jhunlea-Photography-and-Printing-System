<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every web response (Phase 9A). A header a response already set (a download's own
 * Content-Type handling, for example) is never overwritten. HSTS is only sent over https, so a local http:// address
 * is never pinned. No enforcing Content-Security-Policy: Alpine needs eval and Vite's dev server inline scripts; see
 * docs/SECURITY_AUDIT.md for the Report-Only policy to try later.
 */
class SecurityHeaders
{
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    ];

    public const HSTS = 'max-age=31536000';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', self::HSTS);
        }

        return $response;
    }
}
