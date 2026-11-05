<?php

namespace App\Services\Assistant\Providers;

/**
 * The providers to ask, in order (Phase 7E-1): config assistant.provider first, then config
 * assistant.fallback_provider when it is set and names another provider. make() builds one of them, or returns null
 * when it cannot be used (an unknown name, or no key): the engine then moves on to the next, and answers with the
 * "unavailable" fallback when none is left. Nothing throws.
 */
class ProviderResolver
{
    /** Tests (and only tests) bind a FakeProvider under this prefix plus a provider name, to script one link of the chain. */
    public const BINDING = 'assistant.provider.';

    /** @return list<string> */
    public function chain(): array
    {
        $primary = (string) config('assistant.provider');
        $backup = config('assistant.fallback_provider');

        return is_string($backup) && $backup !== '' && $backup !== $primary ? [$primary, $backup] : [$primary];
    }

    public function make(string $name): ?AssistantProvider
    {
        if (app()->bound(self::BINDING.$name)) {
            return app(self::BINDING.$name);
        }

        return match ($name) {
            'anthropic' => $this->anthropic(),
            'gemini' => $this->gemini(),
            'fake' => app(FakeProvider::class),
            default => null,
        };
    }

    private function anthropic(): ?AnthropicProvider
    {
        $key = $this->key(config('assistant.anthropic.api_key'));

        return $key === null ? null : new AnthropicProvider(
            $key,
            (string) config('assistant.anthropic.model'),
            (string) config('assistant.anthropic.version'),
            (int) config('assistant.timeout_seconds'),
            (int) config('assistant.max_output_tokens'),
            (bool) config('assistant.anthropic.prompt_cache', true),
        );
    }

    private function gemini(): ?GeminiProvider
    {
        $key = $this->key(config('assistant.api_key'));

        return $key === null ? null : new GeminiProvider(
            $key,
            (string) config('assistant.model'),
            (int) config('assistant.timeout_seconds'),
            (int) config('assistant.max_output_tokens'),
            (float) config('assistant.temperature'),
        );
    }

    private function key(mixed $key): ?string
    {
        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }
}
