<?php

namespace App\Services\Assistant\Providers;

use App\Enums\AssistantFeature;

final class ProviderRequest
{
    /** @param list<ProviderMessage> $messages oldest first; the last one is the message to answer */
    public function __construct(
        public readonly string $systemInstruction,
        public readonly array $messages,
        public readonly AssistantFeature $feature,
    ) {}

    /** Every text this request sends, for tests that check nothing private reaches the model. */
    public function promptText(): string
    {
        return $this->systemInstruction."\n".implode("\n", array_map(fn (ProviderMessage $message) => $message->text, $this->messages));
    }
}
