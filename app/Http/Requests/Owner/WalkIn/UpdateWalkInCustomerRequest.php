<?php

namespace App\Http\Requests\Owner\WalkIn;

use App\Http\Requests\Auth\RegisterRequest;
use App\Services\WalkIn\WalkInCustomerService;
use App\Support\PhilippineMobile;
use Illuminate\Foundation\Http\FormRequest;

/** Phase 10J: the owner edits a walk-in customer's name and mobile number (the walk-in has no account of their own). */
class UpdateWalkInCustomerRequest extends FormRequest
{
    protected $errorBag = 'walkIn';

    public function authorize(): bool
    {
        return true; // auth + role:owner on the route
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:'.WalkInCustomerService::NAME_MAX, 'not_regex:/[\x00-\x1F\x7F]/u'],
            'phone' => ['nullable', 'string', new PhilippineMobile],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.*' => 'Type the customer’s name (2 to '.WalkInCustomerService::NAME_MAX.' characters).',
            'phone.*' => RegisterRequest::PHONE_MESSAGE,
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function phone(): ?string
    {
        $phone = trim((string) $this->validated('phone'));

        return $phone === '' ? null : $phone;
    }
}
