<?php

namespace App\Http\Requests\Customer;

use App\Models\PrintingOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** A customer cancels their own order while it is still Submitted (PrintingOrderPolicy::cancel). */
class CancelPrintingOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var PrintingOrder $order */
        $order = $this->route('order');

        return Gate::allows('cancel', $order);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.max' => 'The reason can be up to :max characters.'];
    }
}
