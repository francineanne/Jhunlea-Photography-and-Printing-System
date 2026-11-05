<?php

namespace App\Services\Assistant\Eval;

use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\CustomerRecords;
use App\Services\Assistant\PromptBuilder;
use App\Services\Assistant\Providers\ProviderRequest;

/**
 * The real PromptBuilder, bound in the eval process only, that also keeps the last request it built (Phase 7E-2b) so
 * the prompt_not_contains check can prove that the Redactor hid a phone number or an email. Nothing is changed in the
 * prompt, and the kept request is never written anywhere.
 */
class RecordingPromptBuilder extends PromptBuilder
{
    public ?ProviderRequest $last = null;

    public function build(AssistantInput $input, ?CustomerRecords $records = null): ProviderRequest
    {
        return $this->last = parent::build($input, $records);
    }
}
