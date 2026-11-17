<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Phase 10C: email verification with Laravel's own signed link. "Check your email" (resend, change email, sign out),
 * the link itself (signed, 60 minutes, the user id and the hash of their email checked as Laravel does) and resend
 * (named throttle, 3 per 10 minutes). Every customer feature route has the `verified` middleware (routes/web.php).
 *
 * Phase 10E: the link works on ANY device, signed in or not.
 * - Not signed in: the account is verified and, the first time only (the click that actually verifies it), that
 *   customer is signed in on this device (new session id) and sent to their dashboard or the page they were going to.
 *   An already verified account is never signed in by the link again, so an old email is not a login token. The owner
 *   is never signed in by a link: the owner signs in with the password as always.
 * - Signed in as the same person: verified and sent home, as before.
 * - Signed in as SOMEONE ELSE: the link's account is verified, the signed-in account is kept (never switched), and the
 *   page says so with a masked email.
 * - An expired, tampered or unknown link shows a friendly page (403) with "Send a new link" (which needs sign-in).
 * The waiting "Check your email" page asks GET /email/verify/status every few seconds and moves on by itself.
 */
class EmailVerificationController extends Controller
{
    public const WELCOME = 'Email verified. Welcome!';

    public function notice(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            $home = redirect($request->user()->role->afterLogin($request->session()->pull('url.intended')));

            // The waiting page found the account verified elsewhere (another device): say so once
            return $request->boolean('verified') ? $home->with('status', self::WELCOME) : $home;
        }

        return view('auth.verify-email', ['email' => $request->user()->email]);
    }

    public function verify(Request $request, string $id, string $hash): View|RedirectResponse|Response
    {
        $user = ctype_digit($id) ? User::query()->where('is_walk_in', false)->find((int) $id) : null;

        // Laravel's own checks, made here so a bad link gets a friendly page instead of a bare 403
        if (! $request->hasValidSignature() || $user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->view('auth.verify-link', ['state' => 'invalid', 'signedIn' => $request->user() !== null], 403);
        }

        $current = $request->user();
        // The same person signed in here: work on the signed-in model itself, so this request already sees the change
        if ($current !== null && $current->is($user)) {
            $user = $current;
        }

        $justVerified = false;
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            $justVerified = true;
        }

        if ($current !== null && $current->is($user)) {
            // Back to the page the customer was on before signing up (the booking wizard, say), else their home
            return redirect($user->role->afterLogin($request->session()->pull('url.intended')))->with('status', 'Your email is verified. Welcome!');
        }

        if ($current !== null) {
            return view('auth.verify-link', ['state' => 'other', 'masked' => self::mask($user->getEmailForVerification()), 'signedIn' => true]);
        }

        if ($justVerified && $user->role === UserRole::Customer) {
            Auth::login($user);
            $request->session()->regenerate();

            return redirect($user->role->afterLogin($request->session()->pull('url.intended')))->with('status', self::WELCOME);
        }

        return redirect()->route('login')->with('status', $justVerified
            ? 'Your email is verified. Log in to continue.'
            : 'Your email is already verified. Log in to continue.');
    }

    /** Phase 10E: polled by "Check your email"; says only whether the signed-in account is verified now. */
    public function status(Request $request): JsonResponse
    {
        return response()->json(['verified' => $request->user()->hasVerifiedEmail()])->header('Cache-Control', 'no-store, private');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect($request->user()->role->home());
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'We sent a new verification link to '.$request->user()->email.'.');
    }

    /** "an***@example.test": enough to recognise one's own address, not enough to read someone else's. */
    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1))).'***'.($domain !== '' ? '@'.$domain : '');
    }
}
