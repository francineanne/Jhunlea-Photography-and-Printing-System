<?php

namespace App\Http\Requests\Owner;

use App\Models\PackageImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Used for both create and update. The photo follows the same rules as package photos: type and size are checked from the file itself. */
class ThemeRequest extends FormRequest
{
    public const MAX_SWATCHES = 6;

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function prepareForValidation(): void
    {
        $swatches = $this->input('color_swatches');

        // Empty slots of the form are dropped; colors are kept in one form (#RRGGBB, upper case) so they compare and display the same
        if (is_array($swatches)) {
            $swatches = array_values(array_map(
                fn ($color) => is_string($color) ? strtoupper(trim($color)) : $color,
                array_filter($swatches, fn ($color) => ! (is_string($color) && trim($color) === '') && $color !== null),
            ));
        }

        $this->merge([
            'color_swatches' => $swatches ?? [],
            'is_published' => $this->boolean('is_published'),
            'remove_image' => $this->boolean('remove_image'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color_swatches' => ['array', 'max:'.self::MAX_SWATCHES],
            'color_swatches.*' => ['string', 'regex:/^#[0-9A-F]{6}$/'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.PackageImage::MAX_SIZE_KB, 'dimensions:max_width='.PackageImage::MAX_PIXELS.',max_height='.PackageImage::MAX_PIXELS],
            'remove_image' => ['boolean'],
            'is_published' => ['boolean'],
            'package_ids' => ['nullable', 'array'],
            'package_ids.*' => ['integer', Rule::exists('photography_packages', 'id')->whereNull('deleted_at')],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $file = $this->file('image');

            // PHP drops a file bigger than its own limit before Laravel sees it: say so plainly
            if ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $validator->errors()->add('image', $file->getClientOriginalName().' is bigger than this server accepts ('.ini_get('upload_max_filesize').' per file).');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $types = 'The photo must be a JPG, PNG or WebP file.';

        return [
            'color_swatches.max' => 'Add at most '.self::MAX_SWATCHES.' colors.',
            'color_swatches.*.regex' => 'Each color must look like #C62828 (a # and six letters or numbers).',
            'image.file' => $types,
            'image.image' => $types,
            'image.mimes' => $types,
            'image.dimensions' => 'The photo can be at most '.PackageImage::MAX_PIXELS.' pixels wide or tall.',
            'image.max' => 'The photo can be at most '.(PackageImage::MAX_SIZE_KB / 1024).' MB.',
            'image.uploaded' => 'The photo could not be uploaded. Try again with a smaller file.',
            'package_ids.*.exists' => 'One of the chosen packages does not exist any more.',
        ];
    }
}
