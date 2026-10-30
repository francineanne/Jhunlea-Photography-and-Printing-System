<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['role' => EnsureRole::class]);

        // Trust X-Forwarded-* only from a tunnel on this machine (ngrok), so asset links use https; never '*' (spoofable IPs break rate limits)
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // Check the role before route model binding, so a customer gets 403 (not 404) for an id that does not exist
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureRole::class);

        // Signs out other sessions of a user as soon as their password changes (for example after a reset)
        $middleware->web(append: [AuthenticateSession::class]);

        // Signed-in users who open a guest-only page (login, register...) go to their own home
        $middleware->redirectUsersTo(fn () => auth()->user()->role->home());
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
