<?php

namespace App\Http\Requests\Owner\Faqs;

use App\Models\Faq;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Move a question one place up or down. `from` is the position the owner saw on the page: FaqService moves only when
 * the question is still there, so a double click moves it once.
 */
class MoveFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.faqs.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', 'in:up,down'],
            'from' => ['required', 'integer', 'min:1', 'max:'.Faq::MAX_ITEMS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['direction.*' => 'Choose up or down.', 'from.*' => 'The list changed. Please try again.'];
    }

    public function step(): int
    {
        return $this->validated('direction') === 'up' ? -1 : 1;
    }

    public function from(): int
    {
        return (int) $this->validated('from');
    }
}
