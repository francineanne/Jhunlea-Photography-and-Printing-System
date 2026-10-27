<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $credentials = $request->only('email');

        // Sent after the response: a registered email would otherwise answer slower (mail is sent) or fail with a 500 if mail is down
        dispatch(fn () => Password::sendResetLink($credentials))->afterResponse();

        // Same answer for every outcome (unknown email, throttled, sent), so the form cannot be used to find registered emails
        return back()->with('status', 'If that email address is registered, we have sent a password reset link to it.');
    }
}
