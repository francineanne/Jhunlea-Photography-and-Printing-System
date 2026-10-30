{{-- The question form, shared by "Add a question" and "Edit". $faq is the question (a new, unsaved one on create). --}}
@php($published = (bool) old('is_published', $faq->is_published ?? true))

@if ($errors->any())
    <x-ui.notice class="mb-4 !border-status-red/30 !bg-status-red-bg" role="alert">Please check the fields marked below. Nothing was saved.</x-ui.notice>
@endif

<div class="space-y-4" x-data="{ question: {{ \Illuminate\Support\Js::from((string) old('question', $faq->question)) }}, answer: {{ \Illuminate\Support\Js::from((string) old('answer', $faq->answer)) }} }">
    <div>
        <x-ui.input label="Question" name="question" :required="true" maxlength="{{ \App\Models\Faq::QUESTION_MAX }}" :value="old('question', $faq->question)" x-model="question"
            hint="Up to {{ \App\Models\Faq::QUESTION_MAX }} characters. Plain text." />
        <p class="mt-1 text-right font-mono text-[11px] text-app-text-2" aria-hidden="true" x-cloak x-show="true"><span x-text="question.length"></span> / {{ \App\Models\Faq::QUESTION_MAX }}</p>
    </div>
    <div>
        <x-ui.textarea label="Answer" name="answer" :required="true" rows="6" maxlength="{{ \App\Models\Faq::ANSWER_MAX }}" x-model="answer"
            hint="Up to {{ \App\Models\Faq::ANSWER_MAX }} characters. Plain text; line breaks are kept.">{{ old('answer', $faq->answer) }}</x-ui.textarea>
        <p class="mt-1 text-right font-mono text-[11px] text-app-text-2" aria-hidden="true" x-cloak x-show="true"><span x-text="answer.length"></span> / {{ \App\Models\Faq::ANSWER_MAX }}</p>
    </div>
    <input type="hidden" name="is_published" value="0">
    <x-ui.checkbox name="is_published" value="1" :checked="$published">Show this question on the public FAQ page</x-ui.checkbox>
</div>
