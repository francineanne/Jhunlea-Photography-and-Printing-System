<?php

namespace App\Http\Requests\Owner\Faqs;

/** Add a question at the end of the list. */
class StoreFaqRequest extends FaqFormRequest
{
    protected function getRedirectUrl(): string
    {
        return route('owner.faqs.create');
    }
}
