<?php

namespace App\Services\Assistant\Owner;

use App\Enums\AssistantOutcome;

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
    ) {}

    /** @return array<string, mixed> the JSON the endpoint returns (the provider's name is never shown) */
    public function toArray(): array
    {
        return ['reply' => $this->reply, 'cards' => $this->cards, 'links' => $this->links, 'tools_used' => $this->tools, 'via_backup' => $this->viaBackup];
    }

    /** @return array<string, mixed> what the conversation keeps of an answer */
    public function meta(): array
    {
        return ['cards' => $this->cards, 'links' => $this->links, 'tools' => $this->tools, 'via_backup' => $this->viaBackup];
    }
}
