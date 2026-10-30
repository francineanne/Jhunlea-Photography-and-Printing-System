<?php

namespace App\Http\Requests\Owner\Settings;

use App\Support\ShopSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The six upload limits on the Settings page. Each is a whole number from 1 up to what this server accepts
 * (ShopSettings::serverMax()), and each total must be at least its per-file limit. Nothing is saved unless all pass.
 */
class UploadLimitsRequest extends FormRequest
{
    /** @var array<string, string> the words used in the messages */
    public const LABELS = [
        'printing_max_file_mb' => 'Largest printing file',
        'printing_max_total_mb' => 'All files of one order',
        'printing_max_files' => 'Files per order',
        'booking_max_image_mb' => 'Largest reference image',
        'booking_max_images' => 'Reference images per booking',
        'booking_max_total_mb' => 'All images of one booking',
    ];

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
        $settings = app(ShopSettings::class);
        $rules = [];

        foreach (array_keys(ShopSettings::LIMITS) as $field) {
            $rules[$field] = ['required', 'integer', 'min:1', 'max:'.$settings->serverMax($field)];
        }

        $rules['printing_max_total_mb'][] = 'gte:printing_max_file_mb';
        $rules['booking_max_total_mb'][] = 'gte:booking_max_image_mb';

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [];

        foreach (self::LABELS as $field => $label) {
            $messages["{$field}.required"] = "{$label}: enter a number.";
            $messages["{$field}.integer"] = "{$label}: enter a whole number, for example 10.";
            $messages["{$field}.min"] = "{$label}: the lowest is 1.";
            $messages["{$field}.max"] = "{$label}: this server accepts at most :max.";
        }

        $messages['printing_max_total_mb.gte'] = 'All files of one order must be at least the largest printing file.';
        $messages['booking_max_total_mb.gte'] = 'All images of one booking must be at least the largest reference image.';

        return $messages;
    }

    /** @return array<string, int> */
    public function limits(): array
    {
        return array_map('intval', $this->safe()->only(array_keys(ShopSettings::LIMITS)));
    }
}
