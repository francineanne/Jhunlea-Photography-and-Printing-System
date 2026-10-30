<?php

namespace App\Http\Requests\Chat;

use App\Services\Chat\StudioInbox;
use Illuminate\Validation\Rule;

/** The owner's inbox filters: one of StudioInbox::FILTERS, a search text, and the page. */
class InboxRequest extends ChatRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', Rule::in(StudioInbox::FILTERS)],
            'q' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
            'poll' => ['nullable', 'boolean'],
        ];
    }

    /** A background refresh: it says nothing about the owner being at the screen. */
    public function isPoll(): bool
    {
        return $this->boolean('poll');
    }

    public function filter(): ?string
    {
        return $this->filled('filter') ? (string) $this->input('filter') : null;
    }

    public function search(): ?string
    {
        return $this->filled('q') ? trim((string) $this->input('q')) : null;
    }
}
