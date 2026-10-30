<?php

namespace App\Services\Assistant\Providers;

/**
 * The provider named in config assistant.provider, or null when it cannot be used (an unknown name, or Gemini without
 * a key). Null makes the engine answer with the "unavailable" fallback; nothing throws.
 */
class ProviderResolver
{
    public function resolve(): ?AssistantProvider
    {
        return match (config('assistant.provider')) {
            'gemini' => $this->gemini(),
            'fake' => app(FakeProvider::class),
            default => null,
        };
    }

    private function gemini(): ?GeminiProvider
    {
        $key = config('assistant.api_key');

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return new GeminiProvider(
            trim($key),
            (string) config('assistant.model'),
            (int) config('assistant.timeout_seconds'),
            (int) config('assistant.max_output_tokens'),
            (float) config('assistant.temperature'),
        );
    }
}
