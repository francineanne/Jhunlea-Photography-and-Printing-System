<?php

namespace App\Http\Requests\Concerns;

/**
 * The one rule for a confirmed price typed by the owner, for printing orders and photography bookings alike: what the
 * decimal(10,2) column holds, never more than two decimals, and the words that explain it.
 */
trait ValidatesConfirmedPrice
{
    /** @return array<int, string> */
    protected function priceRules(): array
    {
        return ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'];
    }

    /** @return array<string, string> */
    protected function priceMessages(string $field = 'confirmed_price'): array
    {
        return [
            "{$field}.required" => 'Enter the confirmed price.',
            "{$field}.numeric" => 'The confirmed price must be a number, for example 250 or 250.50.',
            "{$field}.decimal" => 'The confirmed price can have at most 2 decimal places.',
            "{$field}.min" => 'The confirmed price cannot be below ₱0.00.',
            "{$field}.max" => 'The confirmed price cannot be above ₱99,999,999.99.',
        ];
    }
}
