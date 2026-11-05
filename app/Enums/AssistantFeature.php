<?php

namespace App\Enums;

/**
 * Who the assistant is answering and why (Phase 7). ask_lumi talks to a customer, studio_away answers inside the
 * customer's thread with the studio while the owner is away, and the two owner_assist modes only write DRAFTS for the
 * owner (a reply to edit, or a short summary of one thread).
 */
enum AssistantFeature: string
{
    case AskLumi = 'ask_lumi';
    case StudioAway = 'studio_away';
    case OwnerAssistSuggest = 'owner_assist_suggest';
    case OwnerAssistSummarize = 'owner_assist_summarize';
    // Phase 7E-3a: the owner asks about their own shop (OwnerAskService, read-only tools); never through AssistantEngine
    case OwnerAsk = 'owner_ask';

    /** The share of the shop's daily budget this feature draws from (config assistant.limits.global_shares). */
    public function budgetGroup(): string
    {
        return match ($this) {
            self::AskLumi => 'ask_lumi',
            self::StudioAway => 'studio_away',
            self::OwnerAssistSuggest, self::OwnerAssistSummarize => 'owner_assist',
            self::OwnerAsk => 'owner_ask',
        };
    }

    /** @return list<self> */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $feature) => $feature->budgetGroup() === $group));
    }

    public function isOwnerAssist(): bool
    {
        return $this->budgetGroup() === 'owner_assist';
    }

    /** Only replies to customers carry recommendation cards; owner drafts are plain text (owner_ask has its own). */
    public function showsCards(): bool
    {
        return ! $this->isOwnerAssist() && $this !== self::OwnerAsk;
    }

    /** Phase 7E-3a: features answered by OwnerAskService, not AssistantEngine. */
    public function isOwnerAsk(): bool
    {
        return $this === self::OwnerAsk;
    }

    /** The fixed text used when this feature cannot reach the model (config assistant.messages). */
    public function fallbackMessage(): string
    {
        return match ($this) {
            self::AskLumi => config('assistant.messages.unavailable'),
            self::StudioAway => config('assistant.messages.studio_away'),
            self::OwnerAssistSuggest, self::OwnerAssistSummarize => config('assistant.messages.owner_assist'),
            self::OwnerAsk => config('assistant.messages.owner_ask'),
        };
    }

    /** The text for a customer who used up their own daily limit. The other features keep their usual fallback. */
    public function userLimitMessage(): string
    {
        return match ($this) {
            self::AskLumi => config('assistant.messages.limit_user'),
            self::OwnerAsk => config('assistant.messages.owner_ask_limit'),
            default => $this->fallbackMessage(),
        };
    }
}
