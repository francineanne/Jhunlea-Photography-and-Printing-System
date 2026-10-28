<?php

namespace App\Http\Requests\Owner;

use App\Models\PhotographyPackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Used for both create and update; on update the route's package is excluded from the slug check. */
class PackageRequest extends FormRequest
{
    public const MAX_INCLUSIONS = 12;

    public const MAX_INCLUSION_LENGTH = 120;

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
        if (! $this->route('package') && is_string($name) && $slug === Str::slug($name)) {
            $slug = '';
        }

        $this->merge([
            'slug' => $slug,
            'is_published' => $this->boolean('is_published'),
            'is_featured' => $this->boolean('is_featured'),
            'price_requires_confirmation' => $this->boolean('price_requires_confirmation'),
            'inclusions' => $this->inclusionLines(),
        ]);
    }

    /**
     * The textarea holds one item per line. Blank lines are dropped and each line is trimmed. A non-text value is
     * passed on as it is, so validation can reject it instead of the server guessing.
     *
     * @return array<int, mixed>|mixed
     */
    private function inclusionLines(): mixed
    {
        $raw = $this->input('inclusions');

        if ($raw === null) {
            return [];
        }

        if (! is_string($raw)) {
            return $raw;
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $raw) ?: []), fn ($line) => $line !== ''));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var PhotographyPackage|null $current */
        $current = $this->route('package');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(PhotographyPackage::RESERVED_SLUGS), Rule::unique('photography_packages', 'slug')->ignore($current?->id)],
            'category' => ['required', 'string', 'max:60'],
            'short_description' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:3000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'price_requires_confirmation' => ['boolean'],
            'duration_minutes' => ['required', 'integer', 'between:1,1440'],
            'max_people' => ['required', 'integer', 'between:1,500'],
            'digital_outputs' => ['nullable', 'integer', 'between:0,10000'],
            'inclusions' => ['array', 'max:'.self::MAX_INCLUSIONS],
            'inclusions.*' => ['string', 'max:'.self::MAX_INCLUSION_LENGTH],
            'terms' => ['nullable', 'string', 'max:3000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_published' => ['boolean'],
            'is_featured' => ['boolean'],
            'theme_ids' => ['nullable', 'array'],
            'theme_ids.*' => ['integer', Rule::exists('themes', 'id')->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.not_in' => 'This web address is used by the website itself. Choose another one.',
            'slug.regex' => 'The address can only use lowercase letters, numbers and single dashes.',
            'price.decimal' => 'The price can have at most 2 decimal places.',
            'inclusions.max' => 'Add at most '.self::MAX_INCLUSIONS.' inclusions, one per line.',
            'inclusions.*.max' => 'Each inclusion can have at most '.self::MAX_INCLUSION_LENGTH.' characters.',
            'theme_ids.*.exists' => 'One of the chosen themes does not exist any more.',
        ];
    }
}
