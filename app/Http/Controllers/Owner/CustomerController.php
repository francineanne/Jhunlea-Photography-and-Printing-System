<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Customers\CustomerListRequest;
use App\Services\CustomerDirectoryService;
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
            'hasFilters' => $filters['search'] !== '' || $filters['awaiting'],
        ]);
    }

    public function show(int $customer, CustomerDirectoryService $directory): View
    {
        $customer = $directory->find($customer);

        return view('owner.customers.show', ['customer' => $customer, 'data' => $directory->detail($customer)]);
    }
}
