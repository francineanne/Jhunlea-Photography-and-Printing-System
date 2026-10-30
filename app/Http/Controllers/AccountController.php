<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Account\AccountRequest;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Requests\Account\UpdateThemeRequest;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The account page of the signed-in person (Phase 6C), one controller for both areas: /my/account (customers) and
 * /owner/account (the owner). The role middleware on each route group keeps everyone in their own area; every action
 * changes only the signed-in person's own account.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $isCustomer = $user->role === UserRole::Customer;

        return view($isCustomer ? 'customer.account' : 'owner.account', [
            'user' => $user,
            'area' => $isCustomer ? 'customer' : 'owner',
            // Real counts of the customer's own requests (removed ones are not counted); the owner has none of their own
            'counts' => $isCustomer ? [
                'orders' => PrintingOrder::query()->where('customer_id', $user->id)->count(),
                'bookings' => PhotographyBooking::query()->where('customer_id', $user->id)->count(),
            ] : null,
        ]);
    }

    public function profile(UpdateProfileRequest $request): RedirectResponse
    {
        $result = $this->accounts->updateProfile($request->user(), $request->profile());

        $message = match (true) {
            $result['emailChanged'] => 'Profile saved. Use your new email address to sign in from now on.',
            $result['changed'] => 'Profile saved.',
            default => 'Nothing to change: your profile already has these details.',
        };

        return $this->back($request, 'profile', $message);
    }

    public function password(UpdatePasswordRequest $request): RedirectResponse
    {
        $this->accounts->changePassword($request->user(), $request->newPassword(), $request->session());

        return $this->back($request, 'security', 'Password changed. You stay signed in here; every other device where you were signed in has been signed out.');
    }

    public function theme(UpdateThemeRequest $request): RedirectResponse|JsonResponse
    {
        $this->accounts->setTheme($request->user(), $request->theme());

        // The theme button in the header saves in the background
        if ($request->expectsJson()) {
            return response()->json(['theme' => $request->theme()->value]);
        }

        return $this->back($request, 'appearance', 'Appearance saved: '.$request->theme()->label().'.');
    }

    private function back(Request $request, string $section, string $message): RedirectResponse
    {
        return redirect()->to(AccountRequest::pageUrl($request->user()->role).'#'.$section)->with('status', $message);
    }
}
