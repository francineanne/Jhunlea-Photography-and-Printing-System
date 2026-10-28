<?php

namespace App\Services;

use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Places a printing order. The reference number, the estimate, the snapshot and the first history row are saved
 * in one transaction: either the whole order exists or none of it does.
 */
class PrintingOrderService
{
    public function __construct(
        private readonly ReferenceNumberService $references,
        private readonly PricingService $pricing,
        private readonly StatusTransitionService $transitions,
    ) {}

    /**
     * @param  array<int, int|string>  $optionIds
     *
     * @throws AuthorizationException when the user is not a customer
     * @throws InvalidArgumentException when the service is not published or the options, quantity or pages are not valid
     */
    public function place(User $customer, PrintingService $service, array $optionIds, int $quantity, int $pages = 1, ?string $notes = null): PrintingOrder
    {
        if ($customer->role !== UserRole::Customer) {
            throw new AuthorizationException('Only customers place printing orders.');
        }

        if ($service->trashed() || ! $service->is_published) {
            throw new InvalidArgumentException('This printing service is not available.');
        }

        return DB::transaction(function () use ($customer, $service, $optionIds, $quantity, $pages, $notes) {
            $estimate = $this->pricing->estimate($service, $optionIds, $quantity, $pages);
            $notes = $notes === null ? null : trim($notes);

            $order = new PrintingOrder(['quantity' => $quantity, 'notes' => $notes === '' ? null : $notes]);
            $order->forceFill([
                'reference_number' => $this->references->next(ReferenceNumberService::PRINTING),
                'customer_id' => $customer->getKey(),
                'printing_service_id' => $service->getKey(),
                'snapshot' => $estimate->snapshot($service->name, $service->pricing_type->value, $service->base_price),
                'status' => PrintingOrderStatus::Submitted,
                'estimated_price' => $estimate->total,
            ])->save();

            $this->transitions->recordSubmission($order, $customer);

            return $order;
        });
    }
}
