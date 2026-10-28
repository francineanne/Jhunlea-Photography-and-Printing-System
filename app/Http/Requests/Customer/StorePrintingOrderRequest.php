<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\ValidatesOrderFiles;
use Illuminate\Validation\Validator;

/**
 * The whole wizard in one multipart POST: every step is checked again here, whatever the browser did. The file rules
 * (types, sizes, real content) are OrderFileService's own, so they are written in one place only.
 */
class StorePrintingOrderRequest extends PrintingEstimateRequest
{
    use ValidatesOrderFiles;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + $this->fileRules(config('printing.max_files_per_order')) + [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + $this->fileMessages() + [
            'notes.max' => 'Special instructions can be up to :max characters.',
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [...parent::after(), fn (Validator $validator) => $this->checkFiles($validator, $this->service())];
    }

    public function notes(): ?string
    {
        $notes = $this->input('notes');

        return is_string($notes) ? $notes : null;
    }
}
