<?php

namespace App\Services\Assistant\Providers;

use App\Enums\AssistantFeature;

/**
 * One provider call. The system instruction comes in two parts (Phase 7E-2a): a STABLE prefix that is the same for many
 * calls (the rules, the mode, the fixed shop knowledge and the catalog index), which Claude may cache, and a VOLATILE
 * tail that changes per call (the item details picked for this message, the customer's own records, the live facts).
 * $systemInstruction is both together, the full text a provider without caching (Gemini) receives.
 */
final class ProviderRequest
{
    public readonly string $systemInstruction;

    /** @param list<ProviderMessage> $messages oldest first; the last one is the message to answer */
    public function __construct(
        public readonly string $stableSystem,
        public readonly array $messages,
        public readonly AssistantFeature $feature,
        public readonly string $volatileSystem = '',
    ) {
        $this->systemInstruction = $volatileSystem === '' ? $stableSystem : $stableSystem."\n\n".$volatileSystem;
    }

    /** Every text this request sends, for tests that check nothing private reaches the model. */
    public function promptText(): string
    {
        return $this->systemInstruction."\n".implode("\n", array_map(fn (ProviderMessage $message) => $message->text, $this->messages));
    }
}
