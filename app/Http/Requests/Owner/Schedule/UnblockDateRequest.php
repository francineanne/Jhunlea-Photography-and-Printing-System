<?php

namespace App\Http\Requests\Owner\Schedule;

use Illuminate\Foundation\Http\FormRequest;

/** Removes one blocked day. Nothing is typed; the day comes from the address. */
class UnblockDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
