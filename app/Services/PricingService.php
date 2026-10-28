<?php

namespace App\Services;

use App\Enums\PricingType;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Support\Money;
use App\Support\PriceEstimate;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Works out the estimated price of a printing order from the service and the options picked.
 *
 * unit price = base price + the price modifier of every chosen option (never below zero)
 * per page   : unit price x pages x copies
 * per copy   : unit price x copies
 * quotation  : no estimate; the owner quotes the price after reviewing the request
 *
 * All arithmetic is on whole centavos (see Money), so no float ever touches a price. The result is an estimate;
 * only the owner's confirmed price is final.
 */
class PricingService
{
    // decimal(10,2) holds up to 99,999,999.99
    private const MAX_CENTS = 9_999_999_999;

    /**
     * @param  array<int, int|string>  $optionIds  ids of the printing_service_options the customer picked
     *
     * @throws InvalidArgumentException when an option does not belong to the service, is switched off, shares a group
     *                                  with another pick, or the quantity, pages or total is out of range
     */
    public function estimate(PrintingService $service, array $optionIds, int $quantity, int $pages = 1): PriceEstimate
    {
        if ($quantity < 1 || $quantity > config('printing.max_quantity')) {
            throw new InvalidArgumentException('The quantity is out of range.');
        }

        if ($pages < 1 || $pages > config('printing.max_pages')) {
            throw new InvalidArgumentException('The number of pages is out of range.');
        }

        $options = $this->resolveOptions($service, $optionIds);
        $perPage = $service->pricing_type === PricingType::PerPage;
        $snapshotPages = $perPage ? $pages : null;
        $snapshotOptions = $options->map(fn ($option) => [
            'id' => $option->id,
            'group' => $option->group->value,
            'label' => $option->label,
            'price_modifier' => $option->price_modifier,
        ])->values()->all();

        if ($service->pricing_type === PricingType::Quotation || $service->base_price === null) {
            return new PriceEstimate(null, null, $snapshotOptions, $quantity, $snapshotPages);
        }

        $unit = Money::toCents($service->base_price);

        foreach ($options as $option) {
            $unit += Money::toCents($option->price_modifier);
        }

        $unit = max(0, $unit);
        $total = $unit * $quantity * ($perPage ? $pages : 1);

        if ($total > self::MAX_CENTS) {
            throw new InvalidArgumentException('The estimated total is too large.');
        }

        return new PriceEstimate(Money::fromCents($total), Money::fromCents($unit), $snapshotOptions, $quantity, $snapshotPages);
    }

    /**
     * @param  array<int, int|string>  $optionIds
     * @return Collection<int, PrintingServiceOption>
     */
    private function resolveOptions(PrintingService $service, array $optionIds)
    {
        $ids = collect($optionIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $options = $service->options()->active()->whereIn('id', $ids)->get();

        if ($options->count() !== $ids->count()) {
            throw new InvalidArgumentException('One of the chosen options is not available for this service.');
        }

        if ($options->map(fn ($option) => $option->group->value)->unique()->count() !== $options->count()) {
            throw new InvalidArgumentException('Only one option can be chosen from each group.');
        }

        // Keep the order the customer picked them in
        return $ids->map(fn ($id) => $options->firstWhere('id', $id));
    }
}
