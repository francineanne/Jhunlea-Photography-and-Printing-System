<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // New session id after login, so a session id planted before login is useless
        $request->session()->regenerate();

        // Audit trail (8E): the owner's sign-ins only
        if ($request->user()->role === UserRole::Owner) {
            app(AuditLogger::class)->record(AuditAction::LoggedIn, null, actor: $request->user());
        }

        return redirect($request->user()->role->afterLogin($request->session()->pull('url.intended')));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
