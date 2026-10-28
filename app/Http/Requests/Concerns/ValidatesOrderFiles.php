<?php

namespace App\Http\Requests\Concerns;

use App\Models\PrintingService;
use App\Services\OrderFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The files field of every form that sends files for a printing order (the wizard and "add files" on an order).
 * The rules themselves (types, sizes, real content, the total) belong to OrderFileService; this only puts its
 * answers next to the right field.
 */
trait ValidatesOrderFiles
{
    /** @return array<string, array<int, mixed>> */
    protected function fileRules(int $room): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.max(1, $room)],
            'files.*' => ['required', 'file'],
        ];
    }

    /** @return array<string, string> */
    protected function fileMessages(): array
    {
        return [
            'files.required' => 'Add at least one file to print.',
            'files.min' => 'Add at least one file to print.',
            'files.max' => 'You can send up to :max files with one order.',
            'files.*.required' => 'One of the files did not arrive. Please choose it again.',
            'files.*.file' => 'One of the files did not upload completely. Please choose it again.',
        ];
    }

    protected function checkFiles(Validator $validator, ?PrintingService $service, int $alreadyStoredBytes = 0): void
    {
        $files = app(OrderFileService::class);

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
                $files->validate($file, $service);
            } catch (ValidationException $e) {
                $validator->errors()->add("files.{$index}", $file->getClientOriginalName().': '.collect($e->errors())->flatten()->first());
            }
        }

        try {
            $files->validateTotal($this->uploads(), $alreadyStoredBytes);
        } catch (ValidationException $e) {
            $validator->errors()->add('files', collect($e->errors())->flatten()->first());
        }
    }

    /** @return list<UploadedFile> */
    public function uploads(): array
    {
        return array_values(array_filter((array) $this->file('files', []), fn ($file) => $file instanceof UploadedFile));
    }
}
