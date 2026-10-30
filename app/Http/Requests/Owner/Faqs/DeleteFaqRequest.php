<?php

namespace App\Http\Requests\Owner\Faqs;

use Illuminate\Foundation\Http\FormRequest;

/** Delete a question for good. The confirm step (a modal, or the confirm page without JavaScript) sends `confirm`. */
class DeleteFaqRequest extends FormRequest
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
        return ['confirm' => ['accepted']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirm.accepted' => 'Confirm the delete first.'];
    }
}
