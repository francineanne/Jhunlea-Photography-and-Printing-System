<?php

namespace App\Http\Requests\Customer;

use App\Models\PhotographyPackage;
use App\Services\BookingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Which free times the booking wizard asks for: a published package (its session length decides the slots) and a range
 * of local calendar days, at most config booking.max_lookup_days long.
 */
class BookingSlotsRequest extends FormRequest
{
    private ?PhotographyPackage $chosenPackage = null;

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:customer route middleware
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'package' => ['required', 'integer', Rule::exists('photography_packages', 'id')->where('is_published', true)->whereNull('deleted_at')],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'package.*' => BookingService::PACKAGE_MESSAGE,
            'from.*' => 'Choose the first day to look at.',
            'to.after_or_equal' => 'The last day must be on or after the first day.',
            'to.*' => 'Choose the last day to look at.',
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            $max = (int) config('booking.max_lookup_days');

            if ($this->from()->diffInDays($this->to()) >= $max) {
                $validator->errors()->add('to', "Look at up to {$max} days at a time.");
            }
        }];
    }

    public function package(): PhotographyPackage
    {
        return $this->chosenPackage ??= PhotographyPackage::published()->findOrFail((int) $this->input('package'));
    }

    public function from(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', (string) $this->input('from'), config('app.display_timezone'));
    }

    public function to(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', (string) $this->input('to'), config('app.display_timezone'));
    }
}
