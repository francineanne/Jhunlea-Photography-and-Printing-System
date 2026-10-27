<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Usage: ->middleware('role:owner') or 'role:owner,customer'.
     * Guests go to login; a signed-in user with the wrong role gets a 403 instead of a silent redirect.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        abort_unless(in_array($user->role->value, $roles, true), 403);

        return $next($request);
    }
}
