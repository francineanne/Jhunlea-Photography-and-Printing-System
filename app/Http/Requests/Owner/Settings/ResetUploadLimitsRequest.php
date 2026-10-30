<?php

namespace App\Http\Requests\Owner\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** "Use the starting defaults" for the upload limits. Nothing to type: the confirm box must be ticked. */
class ResetUploadLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.settings.index').'#uploads';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['confirm' => ['accepted']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirm.accepted' => 'Tick the box to confirm going back to the starting upload limits.'];
    }
}
