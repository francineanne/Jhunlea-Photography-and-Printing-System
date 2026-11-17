<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Services\PasswordResetCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * "Forgot password" (Phase 10C: a 6-digit code instead of a link). The answer is always the same, so the form cannot be
 * used to find registered emails; then the page where the code and the new password are typed opens. On top of the
 * route's per-IP throttle, each email may ask for at most REQUESTS_PER_EMAIL codes every 10 minutes (429 after that,
 * for any email, known or not).
 */
class PasswordResetLinkController extends Controller
{
    public const REQUESTS_PER_EMAIL = 3;

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(ForgotPasswordRequest $request, PasswordResetCodeService $codes): RedirectResponse
    {
        $email = mb_strtolower(trim((string) $request->validated('email')));
        $key = 'password-code-request:'.sha1($email);

        abort_if(RateLimiter::tooManyAttempts($key, self::REQUESTS_PER_EMAIL), 429);
        RateLimiter::hit($key, 600);

        $codes->request($email);

        return redirect()->route('password.reset', ['email' => $email])->with('status', PasswordResetCodeService::SENT_MESSAGE);
    }
}
