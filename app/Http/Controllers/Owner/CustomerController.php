<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Customers\ClearPrepayRequest;
use App\Http\Requests\Owner\Customers\CustomerListRequest;
use App\Services\CustomerDirectoryService;
use App\Services\PrepayFlagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** The owner's customer directory and one customer's page (read only: no actions here). */
class CustomerController extends Controller
{
    public function index(CustomerListRequest $request, CustomerDirectoryService $directory): View
    {
        $filters = $request->filters();

        return view('owner.customers.index', [
            'customers' => $directory->customers($filters),
            'tiles' => $directory->tiles(),
            'filters' => $filters,
            'hasFilters' => $filters['search'] !== '' || $filters['awaiting'] || $filters['type'] !== null,
        ]);
    }

    public function show(int $customer, CustomerDirectoryService $directory): View
    {
        $customer = $directory->find($customer);

        return view('owner.customers.show', ['customer' => $customer, 'data' => $directory->detail($customer)]);
    }

    /** Phase 10B: the owner clears the "pay before printing" flag (PrepayFlagService, audited). */
    public function clearPrepay(ClearPrepayRequest $request, int $customer, CustomerDirectoryService $directory, PrepayFlagService $flags): RedirectResponse
    {
        $row = $directory->find($customer);
        $cleared = $flags->clear($row, $request->user(), $request->reason());

        return redirect()->to(route('owner.customers.show', $row).'#prepay')
            ->with('status', $cleared ? 'Cleared. New orders of this customer follow the usual payment rules again.' : 'Nothing to clear: this customer pays the usual way.');
    }
}
