<?php

namespace App\Http\Requests\Owner;

use App\Enums\OptionGroup;
use App\Models\PrintingServiceOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);

        // Each option has its own form on one page, so each keeps its own error bag
        /** @var PrintingServiceOption|null $option */
        $option = $this->route('option');
        $this->errorBag = $option ? 'option_'.$option->id : 'new_option';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'group' => ['required', Rule::enum(OptionGroup::class)],
            'label' => ['required', 'string', 'max:80'],
            'price_modifier' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['price_modifier.decimal' => 'The price can have at most 2 decimal places.'];
    }
}
