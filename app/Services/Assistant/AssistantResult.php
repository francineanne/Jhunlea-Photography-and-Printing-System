<?php

namespace App\Services\Assistant;

use App\Enums\AssistantIntent;
use App\Enums\AssistantOutcome;

/**
 * What the engine hands back. Always safe to show: either a checked model reply or one of the fixed texts. $cards are
 * built from the database (see CardBuilder). $isAi is always true: callers label every one of these as written by
 * the assistant, never by a person.
 */
final class AssistantResult
{
    public readonly bool $isAi;

    /** @param list<array{type: string, id: int, name: string, price: ?string, summary: ?string, inclusions: list<string>, url: string}> $cards */
    public function __construct(
        public readonly string $reply,
        public readonly AssistantOutcome $outcome,
        public readonly ?AssistantIntent $intent = null,
        public readonly array $cards = [],
        public readonly bool $needsOwner = false,
    ) {
        $this->isAi = true;
    }
}
