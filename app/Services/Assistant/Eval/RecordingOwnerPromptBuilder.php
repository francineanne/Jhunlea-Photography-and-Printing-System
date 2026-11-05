<?php

namespace App\Services\Assistant\Eval;

use App\Services\Assistant\Owner\OwnerPromptBuilder;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\Assistant\Providers\ProviderRequest;

/**
 * The real OwnerPromptBuilder, bound in the eval process only (Phase 7E-3a), that also keeps the tool results the
 * phrasing call read (for numbers_from_data) and the last request (for prompt_not_contains). Nothing is changed in
 * the prompt, and nothing kept is written anywhere.
 */
class RecordingOwnerPromptBuilder extends OwnerPromptBuilder
{
    /** @var list<ToolResult> */
    public array $results = [];

    public ?ProviderRequest $last = null;

    public function plan(string $message, array $history): ProviderRequest
    {
        return $this->last = parent::plan($message, $history);
    }

    public function phrase(string $message, array $history, array $results): ProviderRequest
    {
        $this->results = $results;

        return $this->last = parent::phrase($message, $history, $results);
    }

    /** @return list<string> */
    public function facts(): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (ToolResult $result) => $result->facts(), $this->results))));
    }
}
