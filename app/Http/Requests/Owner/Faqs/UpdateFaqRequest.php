<?php

namespace App\Http\Requests\Owner\Faqs;

/** Change a question; its place in the list stays the same. */
class UpdateFaqRequest extends FaqFormRequest
{
    protected function getRedirectUrl(): string
    {
        return route('owner.faqs.edit', $this->route('faq'));
    }
}
