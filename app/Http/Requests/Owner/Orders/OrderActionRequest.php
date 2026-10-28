<?php

namespace App\Http\Requests\Owner\Orders;

use App\Http\Requests\Concerns\ValidatesConfirmedPrice;
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
    use ValidatesConfirmedPrice;

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
        return ['note.max' => 'The note can be up to :max characters.'] + $this->priceMessages();
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
