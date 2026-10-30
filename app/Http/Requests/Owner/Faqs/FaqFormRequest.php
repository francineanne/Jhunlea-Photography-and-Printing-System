<?php

namespace App\Http\Requests\Owner\Faqs;

use App\Models\Faq;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The question form, for adding and for changing one. Plain text only: line breaks are kept in the answer, other
 * control characters are refused. A question already in the list (case does not count) is refused by FaqService.
 */
abstract class FaqFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_published' => $this->boolean('is_published')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:'.Faq::QUESTION_MAX, 'not_regex:/[\x00-\x1F\x7F]/u'],
            'answer' => ['required', 'string', 'max:'.Faq::ANSWER_MAX, 'not_regex:/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u'],
            'is_published' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'question.required' => 'Type the question.',
            'question.max' => 'Keep the question to :max characters.',
            'question.*' => 'Type the question as plain text.',
            'answer.required' => 'Type the answer.',
            'answer.max' => 'Keep the answer to :max characters.',
            'answer.*' => 'Type the answer as plain text.',
            'is_published.*' => 'Choose whether the question is shown.',
        ];
    }

    /** @return array{question: string, answer: string, is_published: bool} */
    public function faq(): array
    {
        return [
            'question' => trim((string) $this->validated('question')),
            'answer' => trim(str_replace("\r\n", "\n", (string) $this->validated('answer'))),
            'is_published' => (bool) $this->validated('is_published'),
        ];
    }
}
