<?php

namespace App\Http\Requests\Owner\Orders;

use App\Models\PrintingOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base of the owner's order actions. Which moves are allowed is decided by StatusTransitionService and the status
 * table, never here; this checks the owner may act on the order and validates what was typed. An optional note goes
 * to the order's history.
 */
abstract class OrderActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('changeStatus', $this->order());
    }

    /** A failed action returns to the order, where the errors are shown above the actions. */
    protected function getRedirectUrl(): string
    {
        return route('owner.orders.show', $this->order()).'#actions';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.max' => 'The note can be up to :max characters.',
            'confirmed_price.required' => 'Enter the confirmed price.',
            'confirmed_price.numeric' => 'The confirmed price must be a number, for example 250 or 250.50.',
            'confirmed_price.decimal' => 'The confirmed price can have at most 2 decimal places.',
            'confirmed_price.min' => 'The confirmed price cannot be below ₱0.00.',
            'confirmed_price.max' => 'The confirmed price cannot be above ₱99,999,999.99.',
        ];
    }

    /**
     * The one rule for a confirmed price: what the decimal(10,2) column holds, never more than two decimals.
     *
     * @return array<int, string>
     */
    protected function priceRules(): array
    {
        return ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'];
    }

    public function order(): PrintingOrder
    {
        return $this->route('order');
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
