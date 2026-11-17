<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = new User($request->safe()->only(['name', 'email', 'password']) + ['phone' => $request->phone()]);
        // Never taken from the request: public sign-up always creates a customer
        $user->role = UserRole::Customer;
        $user->save();

        // Phase 10C: Laravel's Registered event sends the verification link (User::sendEmailVerificationNotification)
        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
