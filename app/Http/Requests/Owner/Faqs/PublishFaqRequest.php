<?php

namespace App\Http\Requests\Owner\Faqs;

use Illuminate\Foundation\Http\FormRequest;

/** Show or hide a question. The button sends the state it wants, so a double click sets it once. */
class PublishFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.faqs.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['published' => ['required', 'string', 'in:0,1']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['published.*' => 'Choose show or hide.'];
    }

    public function published(): bool
    {
        return $this->validated('published') === '1';
    }
}
