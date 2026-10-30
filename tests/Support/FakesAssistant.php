<?php

namespace Tests\Support;

use App\Enums\AssistantFeature;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\AssistantResult;
use App\Services\Assistant\Providers\FakeProvider;
use App\Services\Assistant\ThreadMessage;
use Illuminate\Support\Facades\Http;

/**
 * Points the assistant at a FakeProvider and blocks every real HTTP request, so no test can ever reach Gemini.
 * Call fakeAssistant() at the start of a test (or in setUp).
 */
trait FakesAssistant
{
    protected FakeProvider $fake;

    protected function fakeAssistant(): FakeProvider
    {
        Http::preventStrayRequests();
        config(['assistant.provider' => 'fake', 'assistant.api_key' => null, 'assistant.enabled' => true]);

        $this->fake = new FakeProvider;
        $this->app->instance(FakeProvider::class, $this->fake);

        return $this->fake;
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
