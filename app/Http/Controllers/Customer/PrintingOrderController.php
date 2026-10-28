<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OptionGroup;
use App\Enums\PricingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StorePrintingOrderRequest;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Services\OrderFileService;
use App\Services\PrintingOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The customer's printing order wizard (Phase 3B). The four steps are sections of one form, so it works without
 * scripts; Alpine shows them one at a time. Every step is checked again on the server in one POST.
 */
class PrintingOrderController extends Controller
{
    /** Which wizard step holds each field, so a failed submit opens the step with the first problem. */
    private const STEP_OF_FIELD = ['service' => 1, 'options' => 2, 'quantity' => 2, 'pages' => 2, 'files' => 3, 'notes' => 3];

    public function create(OrderFileService $files): View
    {
        Gate::authorize('create', PrintingOrder::class);

        $services = PrintingService::published()
            ->with(['options' => fn ($query) => $query->active()->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // What the browser needs to check files and show the right fields; the server checks everything again
        $rules = $services->mapWithKeys(fn (PrintingService $service) => [$service->id => [
            'perPage' => $service->pricing_type === PricingType::PerPage,
            'extensions' => $files->allowedExtensions($service),
            'maxKb' => $files->maxKilobytes($service),
        ]]);

        return view('customer.printing.create', [
            'services' => $services,
            'rules' => $rules,
            'groups' => OptionGroup::cases(),
            'maxFiles' => config('printing.max_files_per_order'),
            'maxTotalMb' => config('printing.max_total_size_mb'),
            'maxQuantity' => config('printing.max_quantity'),
            'maxPages' => config('printing.max_pages'),
            'startStep' => $this->firstStepWithError(session('errors')),
        ]);
    }

    public function store(StorePrintingOrderRequest $request, PrintingOrderService $orders): RedirectResponse
    {
        try {
            $order = $orders->placeWithFiles(
                $request->user(),
                $request->service(),
                array_values($request->chosenOptionIds()),
                $request->quantity(),
                $request->pages(),
                $request->notes(),
                $request->uploads(),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Your order could not be saved, so nothing was sent. Please choose your files again and try once more.');
        }

        return redirect()->route('customer.printing.placed', $order);
    }

    public function placed(PrintingOrder $order): View
    {
        Gate::authorize('view', $order);

        $order->load(['files' => fn ($query) => $query->orderBy('id')]);

        return view('customer.printing.placed', ['order' => $order]);
    }

    private function firstStepWithError(?ViewErrorBag $errors): int
    {
        if ($errors === null || $errors->isEmpty()) {
            return 1;
        }

        $steps = collect($errors->keys())->map(fn (string $key) => self::STEP_OF_FIELD[strtok($key, '.')] ?? 4);

        return $steps->min();
    }
}
