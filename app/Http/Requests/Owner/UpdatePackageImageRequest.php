<?php

namespace App\Http\Requests\Owner;

use App\Models\PackageImage;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePackageImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function prepareForValidation(): void
    {
        // Each photo has its own form on one page, so each keeps its own error bag
        /** @var PackageImage|null $image */
        $image = $this->route('image');
        $this->errorBag = 'image_'.$image?->id;
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.packages.edit', $this->route('package')).'#gallery';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'alt_text' => ['nullable', 'string', 'max:150'],
        ];
    }
}
