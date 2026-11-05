<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\CustomerDirectoryService;

/**
 * Customers by name (the customers directory's own search), so the owner can pick one when two share a name. Only the
 * id, name, request counts and the amount awaiting payment: never a phone number or an email.
 */
class CustomerSearch extends OwnerTool
{
    public function __construct(private readonly CustomerDirectoryService $directory) {}

    public function name(): string
    {
        return 'customer_search';
    }

    public function description(): string
    {
        return 'Find customers by name: id, name, how many orders and bookings, amount awaiting payment. Use it first when the owner names a customer.';
    }

    public function parameters(): array
    {
        return ['name' => ['type' => 'string', 'required' => true, 'max_length' => 80]];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $result = new ToolResult($this->name());
        $page = $this->directory->customers(['search' => trim($params['name']), 'awaiting' => false]);
        $result->total = $page->total();

        foreach (collect($page->items())->take(self::MAX_ROWS) as $customer) {
            $result->rows[] = self::row($customer, $result);
            $result->card($customer->name, route('owner.customers.show', $customer));
        }

        if ($result->total === 0) {
            $result->question = 'I could not find a customer with that name. Could you check the spelling or give their full name?';
        } elseif ($result->total > 1) {
            $result->data['note'] = 'More than one customer matches: ask the owner which one they mean (by full name).';
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function row(User $customer, ToolResult $result): array
    {
        return [
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'orders' => $result->count((int) $customer->printing_orders_count),
            'bookings' => $result->count((int) $customer->photography_bookings_count),
            'awaiting_payment' => $result->money((int) $customer->awaiting_cents),
            'customer_since' => $result->moment($customer->created_at),
        ];
    }
}
