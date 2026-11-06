<?php

namespace App\Http\Requests\Owner\WalkIn;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The two JSON lookups of the counter forms (Phase 8A): customers by name or phone (`q`), and the free start times of a
 * package on a date (`package`, `date`). A bad value answers 422 JSON, never a redirect.
 */
class WalkInLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth + role:owner on the route
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'package' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Check what you typed.'], 422));
    }
}
