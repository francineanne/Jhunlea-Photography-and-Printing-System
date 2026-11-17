<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 10C: email verification with Laravel's own signed link. "Check your email" (resend, change email, sign out),
 * the link itself (signed, 60 minutes, only for the signed-in account whose id and email hash it carries), and resend
 * (named throttle, 3 per 10 minutes). Every customer feature route has the `verified` middleware (routes/web.php).
 */
class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect($request->user()->role->home())
            : view('auth.verify-email', ['email' => $request->user()->email]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->markEmailAsVerified();
            event(new Verified($request->user()));
        }

        // Back to the page the customer was on before signing up (the booking wizard, say), else their home
        return redirect($request->user()->role->afterLogin($request->session()->pull('url.intended')))->with('status', 'Your email is verified. Welcome!');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect($request->user()->role->home());
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'We sent a new verification link to '.$request->user()->email.'.');
    }
}
