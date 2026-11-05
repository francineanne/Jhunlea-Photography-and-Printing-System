<?php

namespace App\Services\Assistant\Owner;

use App\Enums\AssistantOutcome;
use App\Services\Assistant\LimitService;

/** What the owner's Ask Lumi answers (Phase 7E-3a). $tools holds tool NAMES only. */
final readonly class OwnerAskResult
{
    /**
     * @param  list<array{label: string, url: string}>  $cards
     * @param  list<array{label: string, url: string}>  $links
     * @param  list<string>  $tools
     */
    public function __construct(
        public string $reply,
        public AssistantOutcome $outcome,
        public array $cards = [],
        public array $links = [],
        public array $tools = [],
        public ?string $provider = null,
        public bool $viaBackup = false,
        // For a limit outcome: LimitService::MINUTE or LimitService::DAY (7E-3b)
        public ?string $limit = null,
    ) {}

    /**
     * What the page should show as a calm notice instead of an answer (7E-3b): limit_minute, limit_day, unavailable
     * (the providers did not answer, an error, switched off), or null for an answer, a question or a refusal.
     */
    public function notice(): ?string
    {
        return match ($this->outcome) {
            AssistantOutcome::FallbackLimitUser => $this->limit === LimitService::MINUTE ? 'limit_minute' : 'limit_day',
            AssistantOutcome::FallbackLimitGlobal, AssistantOutcome::FallbackUnavailable, AssistantOutcome::FallbackError, AssistantOutcome::FallbackDisabled => 'unavailable',
            default => null,
        };
    }

    /** @return array<string, mixed> the JSON the endpoint returns (the provider's name is never shown) */
    public function toArray(): array
    {
        return ['reply' => $this->reply, 'cards' => $this->cards, 'links' => $this->links, 'tools_used' => $this->tools, 'via_backup' => $this->viaBackup, 'notice' => $this->notice()];
    }

    /** @return array<string, mixed> what the conversation keeps of an answer */
    public function meta(): array
    {
        // verified: this answer passed the owner guards (or was written by code), so a follow-up may repeat its numbers
        return ['cards' => $this->cards, 'links' => $this->links, 'tools' => $this->tools, 'via_backup' => $this->viaBackup,
            'outcome' => $this->outcome->value, 'verified' => in_array($this->outcome, [AssistantOutcome::Answered, AssistantOutcome::AnsweredByCode], true)];
    }
}
