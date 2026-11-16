<?php

namespace App\Http\Requests\Owner\Settings;

use App\Support\ShopSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 10B: the shop's payment rules (each within config payments.limits) and the GCash name and number shown to
 * customers (display only; the system never takes a payment online).
 */
class PaymentRulesRequest extends FormRequest
{
    public const LABELS = [
        'downpayment_percent' => 'Downpayment percent',
        'downpayment_due_days' => 'Downpayment due days before the shoot',
        'downpayment_min_hours' => 'Minimum hours to pay',
        'prepay_from' => 'Pay first from (printing)',
        'unclaimed_after_days' => 'Unclaimed after days',
    ];

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.settings.index').'#payments';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = [];

        foreach (ShopSettings::PAYMENT_RULES as $field) {
            [$min, $max] = config("payments.limits.{$field}");
            $rules[$field] = ['required', 'integer', "min:{$min}", "max:{$max}"];
        }

        [$min, $max] = config('payments.limits.prepay_from');

        return $rules + [
            'prepay_from' => ['required', 'numeric', "min:{$min}", "max:{$max}", 'decimal:0,2'],
            'gcash_name' => ['nullable', 'string', 'max:80', 'not_regex:/[\x00-\x1F\x7F]/u'],
            // Digits, spaces, + and hyphens only, 7 to 20 characters
            'gcash_number' => ['nullable', 'string', 'regex:/^[0-9+ -]{7,20}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [];

        foreach (self::LABELS as $field => $label) {
            [$min, $max] = config("payments.limits.{$field}");
            $messages["{$field}.*"] = "{$label} must be a number from {$min} to {$max}.";
        }

        return $messages + [
            'gcash_name.*' => 'Type the GCash account name as plain text, up to 80 characters.',
            'gcash_number.*' => 'Type the GCash number with digits only (spaces, + and - are fine), 7 to 20 characters.',
        ];
    }

    /** @return array<string, int|string|null> */
    public function values(): array
    {
        $text = fn (string $field) => is_string($this->validated($field)) && trim($this->validated($field)) !== '' ? trim($this->validated($field)) : null;
        $values = [];

        foreach (ShopSettings::PAYMENT_RULES as $field) {
            $values[$field] = (int) $this->validated($field);
        }

        return $values + [
            'prepay_from' => number_format((float) $this->validated('prepay_from'), 2, '.', ''),
            'gcash_name' => $text('gcash_name'),
            'gcash_number' => $text('gcash_number'),
        ];
    }
}
