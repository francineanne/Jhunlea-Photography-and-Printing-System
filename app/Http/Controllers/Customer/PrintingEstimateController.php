<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OptionGroup;
use App\Enums\PricingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\PrintingEstimateRequest;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

/**
 * The live estimate of the printing wizard. It answers with exactly what PricingService worked out, the same result
 * the order will be saved with. A null total (quotation, or no price set) is sent as "Price quoted after review".
 */
class PrintingEstimateController extends Controller
{
    public function __invoke(PrintingEstimateRequest $request): JsonResponse
    {
        $service = $request->service();
        $estimate = $request->estimate();
        $perPage = $service->pricing_type === PricingType::PerPage;
        $quoted = $estimate->total === null;

        $lines = [];

        if (! $quoted) {
            $lines[] = ['label' => $perPage ? 'Base price per page' : 'Base price per copy', 'value' => Money::format($service->base_price)];
        }

        foreach ($estimate->options as $option) {
            $lines[] = [
                'label' => OptionGroup::from($option['group'])->label().': '.$option['label'],
                'value' => $quoted ? 'Priced on review' : Money::modifier($option['price_modifier']),
            ];
        }

        if (! $quoted) {
            $lines[] = ['label' => $perPage ? 'Price per page' : 'Price per copy', 'value' => Money::format($estimate->unitPrice)];
            $lines[] = $perPage
                ? ['label' => 'Pages × copies', 'value' => $estimate->pages.' × '.$estimate->quantity]
                : ['label' => 'Copies', 'value' => (string) $estimate->quantity];
        }

        return response()->json([
            'quoted' => $quoted,
            'total' => $estimate->total,
            'total_text' => $quoted ? 'Price quoted after review' : Money::format($estimate->total),
            'lines' => $lines,
        ]);
    }
}
