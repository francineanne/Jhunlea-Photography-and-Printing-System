<?php

namespace App\Support;

/**
 * What PricingService worked out. The total is only an estimate: the owner confirms the final price.
 * A null total means the service is priced by quotation (or has no price set), so there is nothing to estimate.
 */
final readonly class PriceEstimate
{
    /**
     * @param  list<array{id: int, group: string, label: string, price_modifier: string}>  $options
     */
    public function __construct(
        public ?string $total,
        public ?string $unitPrice,
        public array $options,
        public int $quantity,
        public ?int $pages,
    ) {}

    /** The record kept on the order, so a later price change in the catalog never rewrites an old order. */
    public function snapshot(string $serviceName, string $pricingType, ?string $basePrice): array
    {
        return [
            'service' => ['name' => $serviceName, 'pricing_type' => $pricingType, 'base_price' => $basePrice],
            'options' => $this->options,
            'pages' => $this->pages,
            'unit_price' => $this->unitPrice,
        ];
    }
}
