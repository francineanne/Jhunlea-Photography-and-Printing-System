<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\WalkIn\UpdateWalkInCustomerRequest;
use App\Models\User;
use App\Services\WalkIn\WalkInCustomerService;
use App\Services\WalkIn\WalkInDesk;
use App\Support\ShopSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 10J: the ONE place to start a walk-in (/owner/walk-in): the two counter forms, "Find a walk-in customer",
 * what needs the owner (walk-ins are never notified, so the shop calls), and the list of walk-in customers. The
 * walk-in customer's own page is the owner customer page, with "Edit name and phone" here.
 */
class WalkInDeskController extends Controller
{
    public function index(Request $request, WalkInDesk $desk): View
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return view('owner.walk-in.index', [
            'attention' => $desk->attention(),
            'customers' => $desk->customers($search),
            'search' => $search,
            'unclaimedAfter' => (int) app(ShopSettings::class)->paymentRule('unclaimed_after_days'),
        ]);
    }

    public function updateCustomer(UpdateWalkInCustomerRequest $request, User $customer, WalkInCustomerService $customers): RedirectResponse
    {
        abort_unless($customer->isWalkIn(), 404);

        $customers->update($customer, $request->name(), $request->phone());

        return redirect()->route('owner.customers.show', $customer)->with('status', 'Walk-in customer saved.');
    }
}
