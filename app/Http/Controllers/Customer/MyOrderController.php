<?php

namespace App\Http\Controllers\Customer;

use App\Enums\PrintingOrderStatus;
use App\Exceptions\FileRemovalException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AddOrderFilesRequest;
use App\Http\Requests\Customer\CancelPrintingOrderRequest;
use App\Http\Requests\Customer\PrintingOrderListRequest;
use App\Http\Requests\Customer\RemoveOrderFileRequest;
use App\Models\PrintingOrder;
use App\Services\OrderFileService;
use App\Services\PrintingOrderService;
use App\Services\StatusTransitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The customer's own printing orders: the list, one order, cancelling while it is Submitted and adding files while it
 * is still open. Every query starts from the signed-in customer, so another customer's order is never loaded into a list.
 */
class MyOrderController extends Controller
{
    private const PER_PAGE = 10;

    public function index(PrintingOrderListRequest $request): View
    {
        $status = $request->status();
        $mine = fn () => PrintingOrder::query()->where('customer_id', $request->user()->id);

        $orders = $mine()
            ->with('service')
            ->withCount('files')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('customer.printing.index', [
            'orders' => $orders,
            'status' => $status,
            'counts' => $mine()->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total),
            'statuses' => PrintingOrderStatus::cases(),
        ]);
    }

    public function show(PrintingOrder $order, OrderFileService $files): View
    {
        Gate::authorize('view', $order);

        $order->load([
            'files' => fn ($query) => $query->orderBy('id'),
            'statusHistories',
            'booking',
        ]);

        return view('customer.printing.show', [
            'order' => $order,
            'canCancel' => Gate::allows('cancel', $order),
            'canAddFiles' => Gate::allows('addFiles', $order),
            // Never the last file, so an order always keeps one
            'removable' => $order->files->count() > 1 ? $order->files->filter(fn ($file) => Gate::allows('removeFile', $file))->pluck('id')->all() : [],
            'room' => max(0, config('printing.max_files_per_order') - $order->files->count()),
            'maxFiles' => config('printing.max_files_per_order'),
            'maxFileMb' => intdiv($files->maxKilobytes($order->service), 1024),
            'maxTotalMb' => config('printing.max_total_size_mb'),
            'accept' => collect($files->allowedExtensions($order->service))->map(fn (string $extension) => '.'.$extension)->join(','),
        ]);
    }

    public function cancel(CancelPrintingOrderRequest $request, PrintingOrder $order, StatusTransitionService $transitions): RedirectResponse
    {
        try {
            $transitions->transition($order, PrintingOrderStatus::Cancelled, $request->user(), $request->validated('reason'));
        } catch (InvalidStatusTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('customer.printing.show', $order)->with('status', "Order {$order->reference_number} was cancelled.");
    }

    public function addFiles(AddOrderFilesRequest $request, PrintingOrder $order, PrintingOrderService $orders): RedirectResponse
    {
        $count = count($request->uploads());

        try {
            $orders->addFiles($order, $request->user(), $request->uploads());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('customer.printing.show', $order).'#files');
        } catch (Throwable $e) {
            report($e);

            return redirect()->to(route('customer.printing.show', $order).'#files')->with('error', 'Your files could not be saved, so nothing was added. Please try again.');
        }

        return redirect()->to(route('customer.printing.show', $order).'#files')
            ->with('status', $count === 1 ? 'Your file was added. The shop can see it now.' : "{$count} files were added. The shop can see them now.");
    }

    /**
     * Removes one of the customer's own files. The file is looked up through this order only, so a file of another
     * order is never touched. A file that is already gone (a double click, another tab) or an order that moved on
     * comes back as a message, not an error page.
     */
    public function removeFile(RemoveOrderFileRequest $request, PrintingOrder $order, int $file, PrintingOrderService $orders): RedirectResponse
    {
        $back = redirect()->to(route('customer.printing.show', $order).'#files');
        $record = $order->files()->whereKey($file)->first();

        if ($record !== null) {
            $decision = Gate::inspect('removeFile', $record);

            // Not their upload: a shop file never has a button, so this is a crafted request
            if ($decision->denied() && $decision->code() !== 'closed') {
                abort(403);
            }
        }

        try {
            $name = $orders->removeFile($order, $file, $request->user());
        } catch (FileRemovalException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('status', "\u{201C}{$name}\u{201D} was removed. You can add another file below.");
    }
}
