<?php

namespace App\Http\Requests\Owner\Bookings;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The tabs and filters of the owner's booking list. Without a status the Requested tab opens (the work queue); "all"
 * shows every booking. Anything that does not pass sends the owner back to the default list.
 */
class BookingListRequest extends FormRequest
{
    public const ALL = 'all';

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.bookings.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in([self::ALL, ...array_column(BookingStatus::cases(), 'value')])],
            'q' => ['nullable', 'string', 'max:60'],
            'package' => ['nullable', 'integer', Rule::exists('photography_packages', 'id')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.*' => 'That status filter does not exist.',
            'q.max' => 'Search with up to :max characters.',
            'q.string' => 'Search with plain text.',
            'package.*' => 'That package does not exist.',
            'from.date_format' => 'Pick a valid start date.',
            'to.date_format' => 'Pick a valid end date.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }

    /** The tab: a status, or null for every booking. */
    public function status(): ?BookingStatus
    {
        $value = $this->validated('status');

        return $value === self::ALL ? null : (BookingStatus::tryFrom((string) $value) ?? BookingStatus::Requested);
    }

    public function search(): string
    {
        return trim((string) $this->validated('q'));
    }

    public function package(): ?int
    {
        $package = $this->validated('package');

        return $package === null ? null : (int) $package;
    }

    public function from(): ?string
    {
        return $this->validated('from');
    }

    public function to(): ?string
    {
        return $this->validated('to');
    }
}
