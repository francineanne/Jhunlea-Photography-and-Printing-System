<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        // The chat endpoints (Phase 7B) always answer {code, message}: a missing or someone else's conversation, a
        // wrong role, or too many requests. Their own refusals come from ChatException
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->routeIs('customer.messages.*', 'customer.assistant.*', 'owner.messages.*')) {
                return null;
            }

            $code = match ($e->getStatusCode()) {
                404 => 'not_found',
                403 => 'forbidden',
                429 => 'rate_limited',
                default => 'error',
            };

            return response()->json(['code' => $code, 'message' => config("chat.messages.{$code}") ?? 'Something went wrong.'], $e->getStatusCode(), $e->getHeaders());
        });
    })->create();
