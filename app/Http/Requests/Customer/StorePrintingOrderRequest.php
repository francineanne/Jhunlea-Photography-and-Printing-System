<?php

namespace App\Http\Requests\Customer;

use App\Services\OrderFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The whole wizard in one multipart POST: every step is checked again here, whatever the browser did. The file rules
 * (types, sizes, real content) are OrderFileService's own, so they are written in one place only.
 */
class StorePrintingOrderRequest extends PrintingEstimateRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'files' => ['required', 'array', 'min:1', 'max:'.config('printing.max_files_per_order')],
            'files.*' => ['required', 'file'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'files.required' => 'Add at least one file to print.',
            'files.min' => 'Add at least one file to print.',
            'files.max' => 'You can send up to :max files with one order.',
            'files.*.required' => 'One of the files did not arrive. Please choose it again.',
            'files.*.file' => 'One of the files did not upload completely. Please choose it again.',
            'notes.max' => 'Special instructions can be up to :max characters.',
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            $service = $this->service();

            foreach ($this->uploads() as $index => $file) {
                // PHP drops a file bigger than its own limit before Laravel sees it: say so plainly
                if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    $validator->errors()->add("files.{$index}", $file->getClientOriginalName().' is bigger than this server accepts ('.ini_get('upload_max_filesize').' per file).');

                    continue;
                }

                if ($service === null || $validator->errors()->has("files.{$index}")) {
                    continue;
                }

                try {
                    app(OrderFileService::class)->validate($file, $service);
                } catch (ValidationException $e) {
                    $validator->errors()->add("files.{$index}", $file->getClientOriginalName().': '.collect($e->errors())->flatten()->first());
                }
            }
        }];
    }

    /** @return list<UploadedFile> */
    public function uploads(): array
    {
        return array_values(array_filter((array) $this->file('files', []), fn ($file) => $file instanceof UploadedFile));
    }

    public function notes(): ?string
    {
        $notes = $this->input('notes');

        return is_string($notes) ? $notes : null;
    }
}
