<?php

namespace Tests\Support;

use App\Enums\AssistantFeature;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\AssistantResult;
use App\Services\Assistant\Providers\FakeProvider;
use App\Services\Assistant\Providers\ProviderResolver;
use App\Services\Assistant\ThreadMessage;
use Illuminate\Support\Facades\Http;

/**
 * Points the assistant at a FakeProvider and blocks every real HTTP request, so no test can ever reach a provider.
 * Call fakeAssistant() at the start of a test (or in setUp); fakeChain() scripts Claude and its Gemini backup apart.
 */
trait FakesAssistant
{
    protected FakeProvider $fake;

    protected function fakeAssistant(): FakeProvider
    {
        Http::preventStrayRequests();
        config(['assistant.provider' => 'fake', 'assistant.fallback_provider' => null, 'assistant.api_key' => null, 'assistant.anthropic.api_key' => null, 'assistant.enabled' => true]);

        $this->fake = new FakeProvider;
        $this->app->instance(FakeProvider::class, $this->fake);

        return $this->fake;
    }

    /**
     * Claude first and Gemini as its backup, each a FakeProvider of its own (no key, no network).
     *
     * @return array{0: FakeProvider, 1: FakeProvider} [anthropic, gemini]
     */
    protected function fakeChain(): array
    {
        $this->fakeAssistant();
        config(['assistant.provider' => 'anthropic', 'assistant.fallback_provider' => 'gemini']);

        $claude = new FakeProvider('anthropic');
        $gemini = new FakeProvider('gemini');
        $this->app->instance(ProviderResolver::BINDING.'anthropic', $claude);
        $this->app->instance(ProviderResolver::BINDING.'gemini', $gemini);

        return [$claude, $gemini];
    }

    /** @param list<ThreadMessage> $history */
    protected function ask(string $message, AssistantFeature $feature = AssistantFeature::AskLumi, ?AssistantActor $actor = null, array $history = [], int $consecutiveAiReplies = 0): AssistantResult
    {
        return app(AssistantEngine::class)->handle(new AssistantInput($feature, $actor ?? AssistantActor::guest('203.0.113.7'), $message, $history, $consecutiveAiReplies));
    }

    /** @param list<array{type: string, id: int}> $recommend */
    protected static function reply(string $intent, string $reply, array $recommend = []): string
    {
        return json_encode(['intent' => $intent, 'reply' => $reply, 'recommend' => $recommend], JSON_THROW_ON_ERROR);
    }
}
