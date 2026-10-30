<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Services\Assistant\Exceptions\ProviderBadResponse;
use App\Services\Assistant\Exceptions\ProviderRateLimited;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** Every problem ends in a friendly fixed text; the engine never throws to its caller. */
class AssistantFallbackTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    public function test_an_empty_message_is_rejected_before_anything_else(): void
    {
        config(['assistant.enabled' => false]);

        foreach (['', "   \n\t "] as $message) {
            $result = $this->ask($message);
            $this->assertSame(AssistantOutcome::RejectedInput, $result->outcome);
            $this->assertSame(config('assistant.messages.empty'), $result->reply);
        }

        $this->assertSame([], $this->fake->requests);
    }

    public function test_a_message_over_the_limit_is_rejected(): void
    {
        $this->assertSame(AssistantOutcome::Answered, $this->ask(str_repeat('a', 1000))->outcome);

        $result = $this->ask(str_repeat('a', 1001));

        $this->assertSame(AssistantOutcome::RejectedInput, $result->outcome);
        $this->assertStringContainsString('1000', $result->reply);
        $this->assertCount(1, $this->fake->requests);
    }

    public function test_switched_off_answers_with_the_fallback(): void
    {
        config(['assistant.enabled' => false]);

        $result = $this->ask('Hello');

        $this->assertSame(AssistantOutcome::FallbackDisabled, $result->outcome);
        $this->assertSame('Pahinga muna si Lumi ngayon. You can browse the FAQ or message the studio.', $result->reply);
        $this->assertSame([], $this->fake->requests);
    }

    public function test_no_key_or_an_unknown_provider_answers_with_the_fallback(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.api_key' => null]);
        $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask('Hello')->outcome);

        config(['assistant.provider' => 'nonsense']);
        $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask('Hello')->outcome);

        Http::assertNothingSent();
    }

    public function test_each_feature_has_its_own_fallback_text(): void
    {
        config(['assistant.enabled' => false]);

        $this->assertSame('The studio received your message and will reply when we open.', $this->ask('Hi', AssistantFeature::StudioAway)->reply);
        $this->assertSame('The suggestion is not available right now. You can still reply manually.', $this->ask('Hi', AssistantFeature::OwnerAssistSuggest)->reply);
        $this->assertSame('The suggestion is not available right now. You can still reply manually.', $this->ask('Hi', AssistantFeature::OwnerAssistSummarize)->reply);
    }

    public function test_provider_failures_never_throw(): void
    {
        $this->fake->push(
            new ProviderUnavailable('timeout'),
            new ProviderBadResponse('shape'),
            new RuntimeException('something unexpected'),
            'not json',
        );

        $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask('one')->outcome);
        $this->assertSame(AssistantOutcome::FallbackError, $this->ask('two')->outcome);
        $this->assertSame(AssistantOutcome::FallbackError, $this->ask('three')->outcome);
        $this->assertSame(AssistantOutcome::FallbackError, $this->ask('four')->outcome);
    }

    public function test_a_429_starts_a_cooldown_that_skips_the_provider_until_it_passes(): void
    {
        $this->fake->push(new ProviderRateLimited(30));

        $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask('first')->outcome);
        $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask('second')->outcome);
        $this->assertCount(1, $this->fake->requests);

        $this->travel(31)->seconds();

        $this->assertSame(AssistantOutcome::Answered, $this->ask('third')->outcome);
        $this->assertCount(2, $this->fake->requests);
    }

    public function test_the_cooldown_is_capped(): void
    {
        config(['assistant.limits.provider_cooldown_max_seconds' => 300]);
        $this->fake->push(new ProviderRateLimited(86400));

        $this->ask('first');
        $this->travel(299)->seconds();
        $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask('still waiting')->outcome);

        $this->travel(2)->seconds();
        $this->assertSame(AssistantOutcome::Answered, $this->ask('after the cap')->outcome);
    }

    public function test_studio_away_stops_after_too_many_ai_replies_in_a_row(): void
    {
        config(['assistant.limits.studio_away_max_consecutive' => 5]);

        $this->assertSame(AssistantOutcome::Answered, $this->ask('Hello?', AssistantFeature::StudioAway, consecutiveAiReplies: 4)->outcome);

        $result = $this->ask('Hello again?', AssistantFeature::StudioAway, consecutiveAiReplies: 5);

        $this->assertSame(AssistantOutcome::AwayCapReached, $result->outcome);
        $this->assertSame(config('assistant.messages.away_cap'), $result->reply);
        $this->assertCount(1, $this->fake->requests);
    }
}
