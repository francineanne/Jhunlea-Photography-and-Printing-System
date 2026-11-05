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

    /**
     * @param  list<array{type: string, id: int, name: string, price: ?string, summary: ?string, inclusions: list<string>, url: string}>  $cards
     * @param  array{type: string, id: int}|null  $about  the customer's own order or booking a handoff is about (morph alias + id)
     * @param  ?string  $provider  the provider that wrote the reply ("code" for CodeAnswers), null for a fixed text (Phase 7E-2a)
     * @param  bool  $viaBackup  true when the backup provider wrote it because the main one could not answer (Phase 7E-2a)
     */
    public function __construct(
        public readonly string $reply,
        public readonly AssistantOutcome $outcome,
        public readonly ?AssistantIntent $intent = null,
        public readonly array $cards = [],
        public readonly bool $needsOwner = false,
        public readonly ?array $about = null,
        public readonly ?string $provider = null,
        public readonly bool $viaBackup = false,
    ) {
        $this->isAi = true;
    }

    /** The same result, marked with the provider that wrote it. */
    public function by(string $provider, bool $viaBackup): self
    {
        return new self($this->reply, $this->outcome, $this->intent, $this->cards, $this->needsOwner, $this->about, $provider, $viaBackup);
    }

    /** True when a model or the code wrote this reply: it is stored as Lumi's words, not as a fixed notice. */
    public function fromAssistant(): bool
    {
        return in_array($this->outcome, [AssistantOutcome::Answered, AssistantOutcome::AnsweredByCode, AssistantOutcome::Handoff, AssistantOutcome::Refused], true);
    }
}
