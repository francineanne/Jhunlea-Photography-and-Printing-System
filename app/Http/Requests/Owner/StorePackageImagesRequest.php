<?php

namespace App\Http\Requests\Owner;

use App\Models\PackageImage;
use App\Models\PhotographyPackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/** Photos for one package. The client's file name and extension are never trusted: type and size are checked from the file itself. */
class StorePackageImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    /** A failed upload returns to the gallery, where the error is shown, instead of the top of the page. */
    protected function getRedirectUrl(): string
    {
        return route('owner.packages.edit', $this->route('package')).'#gallery';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.PackageImage::MAX_PER_REQUEST],
            // `image` rejects SVG, and `mimes` checks the guessed type of the content, so a renamed .php or .svg is refused
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.PackageImage::MAX_SIZE_KB, 'dimensions:max_width='.PackageImage::MAX_PIXELS.',max_height='.PackageImage::MAX_PIXELS],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            // PHP drops a file bigger than its own limit before Laravel sees it: say so plainly
            foreach ((array) $this->file('images', []) as $index => $file) {
                if ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    $validator->errors()->add("images.{$index}", $file->getClientOriginalName().' is bigger than this server accepts ('.ini_get('upload_max_filesize').' per file).');
                }
            }

            /** @var PhotographyPackage $package */
            $package = $this->route('package');
            $room = PackageImage::MAX_PER_PACKAGE - $package->images()->count();

            if (count((array) $this->file('images', [])) > $room) {
                $validator->errors()->add('images', 'A package can have at most '.PackageImage::MAX_PER_PACKAGE.' photos. '.($room > 0 ? "You can add {$room} more." : 'Remove one first.'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $types = 'Photos must be JPG, PNG or WebP files.';

        return [
            'images.required' => 'Choose at least one photo to upload.',
            'images.max' => 'You can upload at most '.PackageImage::MAX_PER_REQUEST.' photos at a time.',
            'images.*.file' => $types,
            'images.*.image' => $types,
            'images.*.mimes' => $types,
            'images.*.dimensions' => 'Each photo can be at most '.PackageImage::MAX_PIXELS.' pixels wide or tall.',
            'images.*.max' => 'Each photo can be at most '.(PackageImage::MAX_SIZE_KB / 1024).' MB.',
            'images.*.uploaded' => 'A photo could not be uploaded. Try again with smaller files.',
        ];
    }
}
