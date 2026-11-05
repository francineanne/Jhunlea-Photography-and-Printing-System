<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;

/** One printing order by its PR reference. */
class OrderLookup extends OwnerTool
{
    public function name(): string
    {
        return 'order_lookup';
    }

    public function description(): string
    {
        return 'One printing order by its reference (PR-000123): status, customer name, service, prices, payment and dates.';
    }

    public function parameters(): array
    {
        return ['reference' => ['type' => 'string', 'required' => true, 'max_length' => 20, 'description' => 'PR-000123']];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $result = new ToolResult($this->name());
        $reference = self::normalize($params['reference'], 'PR');
        $order = $reference === null ? null : PrintingOrder::query()->with(['customer:id,name', 'recordedPayment'])->where('reference_number', $reference)->first();

        if ($order === null) {
            $result->question = 'I could not find a printing order with that reference. Which order do you mean (for example PR-000123)?';

            return $result;
        }

        $result->data = RecordFacts::order($order, $result);
        $result->card($order->reference_number, route('owner.orders.show', $order));

        return $result;
    }

    /** "pr123", "PR 000123" and "PR-000123" all become "PR-000123"; anything else is null. */
    public static function normalize(string $reference, string $prefix): ?string
    {
        return preg_match('/^\s*'.$prefix.'[\s-]?(\d{1,9})\s*$/i', $reference, $match) ? $prefix.'-'.str_pad($match[1], 6, '0', STR_PAD_LEFT) : null;
    }
}
