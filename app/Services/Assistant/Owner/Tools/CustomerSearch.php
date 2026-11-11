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
    /** What a walk-in customer is, in every owner tool that shows one (Phase 8D). */
    public const WALK_IN = 'walk-in (no account, never notified by the system; the shop reaches them by the phone number on file)';

    public function __construct(private readonly CustomerDirectoryService $directory) {}

    public function name(): string
    {
        return 'customer_search';
    }

    public function description(): string
    {
        return 'Find customers by name or phone number: id, name, walk-in or online, how many orders and bookings, amount awaiting payment. Use it first when the owner names a customer. type walk_in lists only walk-in customers (no account).';
    }

    public function parameters(): array
    {
        return [
            'name' => ['type' => 'string', 'required' => true, 'max_length' => 80, 'description' => 'a name or a phone number'],
            'type' => ['type' => 'string', 'enum' => ['all', 'walk_in', 'online'], 'default' => 'all'],
        ];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $result = new ToolResult($this->name());
        // The directory's own search (Phase 6A, walk-in type since 8A): name, email or phone, % _ \ matched literally
        $page = $this->directory->customers(['search' => trim($params['name']), 'awaiting' => false, 'type' => $params['type'] === 'all' ? null : $params['type']]);
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
            'type' => $customer->is_walk_in ? self::WALK_IN : 'online account',
            'orders' => $result->count((int) $customer->printing_orders_count),
            'bookings' => $result->count((int) $customer->photography_bookings_count),
            'awaiting_payment' => $result->money((int) $customer->awaiting_cents),
            'customer_since' => $result->moment($customer->created_at),
        ];
    }
}
