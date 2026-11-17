<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\PasswordResetCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Phase 10C: the code from the email and the new password, on one page. PasswordResetCodeService checks the code (hash,
 * 10 minutes, 5 tries); on top of the route's per-IP throttle, each email may try at most TRIES_PER_EMAIL times every 10
 * minutes. An old reset LINK (from before 10C) only leads back to "Forgot password".
 */
class NewPasswordController extends Controller
{
    public const TRIES_PER_EMAIL = 10;

    public function create(Request $request): View
    {
        $email = $request->query('email');

        return view('auth.reset-password', ['email' => is_string($email) ? mb_substr($email, 0, 255) : '']);
    }

    /** An old reset link: the links stopped working in Phase 10C, a code is asked for instead. */
    public function legacy(): RedirectResponse
    {
        return redirect()->route('password.request')->with('status', 'Password reset now uses a 6-digit code sent by email. Enter your email to get one.');
    }

    public function store(ResetPasswordRequest $request, PasswordResetCodeService $codes): RedirectResponse
    {
        $email = mb_strtolower(trim((string) $request->validated('email')));
        $key = 'password-code-verify:'.sha1($email);

        abort_if(RateLimiter::tooManyAttempts($key, self::TRIES_PER_EMAIL), 429);
        RateLimiter::hit($key, 600);

        $user = $codes->reset($email, (string) $request->validated('code'), (string) $request->validated('password'));
        $codes->notifyChanged($user);

        return redirect()->route('login')->with('status', 'Your password was changed. Log in with your new password.');
    }
}
