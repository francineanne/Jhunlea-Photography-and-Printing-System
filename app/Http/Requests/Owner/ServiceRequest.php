<?php

namespace App\Http\Requests\Owner;

use App\Enums\PricingType;
use App\Enums\PrintFileType;
use App\Models\PrintingService;
use App\Support\ShopSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Used for both create and update; on update the route's service is excluded from the slug check. */
class ServiceRequest extends FormRequest
{
    /** The largest file a service may accept: never more than the printing wizard itself takes (ShopSettings). */
    public static function maxFileSizeMb(): int
    {
        return app(ShopSettings::class)->printingMaxFileMb();
    }

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function prepareForValidation(): void
    {
        $slug = is_string($this->input('slug')) ? strtolower(trim($this->input('slug'))) : $this->input('slug');
        $name = $this->input('name');

        // On create, the form fills the slug from the name. If it is just that, treat it as "not chosen" so the server
        // can add a number when the name is already used, instead of failing with "already taken".
        if (! $this->route('service') && is_string($name) && $slug === Str::slug($name)) {
            $slug = '';
        }

        $this->merge(['is_published' => $this->boolean('is_published'), 'slug' => $slug]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var PrintingService|null $current */
        $current = $this->route('service');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('printing_services', 'slug')->ignore($current?->id)],
            'category' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'pricing_type' => ['required', Rule::enum(PricingType::class)],
            'base_price' => [
                Rule::requiredIf(fn () => $this->input('pricing_type') !== PricingType::Quotation->value),
                'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2',
            ],
            'allowed_file_types' => ['required', 'array', 'min:1', 'max:'.count(PrintFileType::cases())],
            'allowed_file_types.*' => ['distinct', Rule::enum(PrintFileType::class)],
            'max_file_size_mb' => ['required', 'integer', 'between:1,'.self::maxFileSizeMb()],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_published' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The address can only use lowercase letters, numbers and single dashes.',
            'base_price.required' => 'Enter a base price, or choose the quotation pricing type.',
            'base_price.decimal' => 'The base price can have at most 2 decimal places.',
            'allowed_file_types.required' => 'Choose at least one file type.',
            'allowed_file_types.min' => 'Choose at least one file type.',
        ];
    }
}
