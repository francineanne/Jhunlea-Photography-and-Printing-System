<?php

namespace App\Http\Controllers\Owner;

use App\Enums\PrintingOrderStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Orders\AcceptOrderRequest;
use App\Http\Requests\Owner\Orders\CancelOrderRequest;
use App\Http\Requests\Owner\Orders\ChangePriceRequest;
use App\Http\Requests\Owner\Orders\CompleteOrderRequest;
use App\Http\Requests\Owner\Orders\MarkProcessingRequest;
use App\Http\Requests\Owner\Orders\MarkReadyRequest;
use App\Http\Requests\Owner\Orders\OrderActionRequest;
use App\Http\Requests\Owner\Orders\RejectOrderRequest;
use App\Http\Requests\Owner\Orders\RequestInfoRequest;
use App\Http\Requests\Owner\Orders\StartReviewRequest;
use App\Models\PrintingOrder;
use App\Services\StatusTransitionService;
use App\Support\Money;
use Closure;
use Illuminate\Http\RedirectResponse;

/**
 * The owner's work on an order. Every action goes through StatusTransitionService, which locks the order, checks the
 * status table and writes the history; nothing here sets a status itself. A refused action (for example a page that
 * was out of date) comes back as a message, not an error page.
 */
class PrintingOrderActionController extends Controller
{
    public function __construct(private readonly StatusTransitionService $transitions) {}

    public function review(StartReviewRequest $request, PrintingOrder $order): RedirectResponse
    {
        return $this->move($request, $order, PrintingOrderStatus::UnderReview, 'Review started.');
    }

    public function accept(AcceptOrderRequest $request, PrintingOrder $order): RedirectResponse
    {
        return $this->act($order, fn () => $this->transitions->transition($order, PrintingOrderStatus::Accepted, $request->user(), $request->note(), $request->price()),
            'Order accepted at '.Money::format($request->price()).'.');
    }

    public function reject(RejectOrderRequest $request, PrintingOrder $order): RedirectResponse
    {
        return $this->act($order, fn () => $this->transitions->transition($order, PrintingOrderStatus::Rejected, $request->user(), $request->reason()),
            'Order rejected. The customer can see your reason.');
    }

    public function requestInfo(RequestInfoRequest $request, PrintingOrder $order): RedirectResponse
    {
        return $this->act($order, fn () => $this->transitions->requestInfo($order, $request->user(), $request->infoMessage()),
            'Your request was added to the order. The customer sees it when they open it.');
    }

    public function processing(MarkProcessingRequest $request, PrintingOrder $order): RedirectResponse
    {
        return $this->move($request, $order, PrintingOrderStatus::Processing, 'Order marked as processing.');
    }

    public function ready(MarkReadyRequest $request, PrintingOrder $order): RedirectResponse
    {
        return $this->move($request, $order, PrintingOrderStatus::ReadyForPickup, 'Order marked as ready for pickup.');
    }

    public function complete(CompleteOrderRequest $request, PrintingOrder $order): RedirectResponse
    {
        return $this->move($request, $order, PrintingOrderStatus::Completed, 'Order completed.');
    }

    public function cancel(CancelOrderRequest $request, PrintingOrder $order): RedirectResponse
    {
        return $this->move($request, $order, PrintingOrderStatus::Cancelled, 'Order cancelled.');
    }

    public function price(ChangePriceRequest $request, PrintingOrder $order): RedirectResponse
    {
        return $this->act($order, fn () => $this->transitions->changePrice($order, $request->user(), $request->price(), $request->reason()),
            'Confirmed price is now '.Money::format($request->price()).'.');
    }

    private function move(OrderActionRequest $request, PrintingOrder $order, PrintingOrderStatus $to, string $done): RedirectResponse
    {
        return $this->act($order, fn () => $this->transitions->transition($order, $to, $request->user(), $request->note()), $done);
    }

    private function act(PrintingOrder $order, Closure $action, string $done): RedirectResponse
    {
        $back = redirect()->to(route('owner.orders.show', $order).'#actions');

        try {
            $action();
        } catch (InvalidStatusTransitionException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('status', $done);
    }
}
