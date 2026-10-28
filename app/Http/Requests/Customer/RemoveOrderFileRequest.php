<?php

namespace App\Http\Requests\Customer;

use App\Models\PrintingOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Removing one file from the customer's own order. The order must be theirs (403 otherwise); the file itself is
 * checked by OrderFilePolicy::removeFile and again by PrintingOrderService::removeFile inside its transaction.
 */
class RemoveOrderFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var PrintingOrder $order */
        $order = $this->route('order');

        return Gate::allows('view', $order);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
