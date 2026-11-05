<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantAuthor;
use App\Enums\AssistantFeature;
use App\Services\Assistant\ThreadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** The rules every prompt carries (Phase 7E-1): the reply language and what Lumi says in the studio thread. */
class PromptRulesTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    public function test_the_reply_language_follows_the_latest_customer_message(): void
    {
        $history = [new ThreadMessage(AssistantAuthor::Customer, 'Magkano po ang tarpaulin?'), new ThreadMessage(AssistantAuthor::Lumi, 'Depende po sa laki.')];

        foreach ([AssistantFeature::AskLumi, AssistantFeature::StudioAway] as $feature) {
            $this->ask('How long does printing take?', $feature, history: $history);
            $system = $this->fake->lastRequest()->systemInstruction;

            $this->assertStringContainsString("Answer in the language of the customer's LATEST message", $system);
            // 7E-2d: shorter rule, plus the detected language of the latest message in the volatile tail
            $this->assertStringContainsString('(English, Filipino, or Taglish when it mixes both); follow the LANGUAGE line below when there is one', $system);
            $this->assertStringContainsString('LANGUAGE OF THE LATEST CUSTOMER MESSAGE (detected by the system): English. Reply in English.', $system);
            $this->assertStringContainsString('Earlier messages do not decide the language', $system);
        }
    }

    public function test_studio_away_no_longer_tells_lumi_to_announce_the_owner_is_away(): void
    {
        $this->ask('Hello, is my print ready?', AssistantFeature::StudioAway);
        $system = $this->fake->lastRequest()->systemInstruction;

        $this->assertStringNotContainsString('Say that the owner is away', $system);
        $this->assertStringNotContainsString('will reply when the studio is open', $system);
        $this->assertStringContainsString('Keep it short.', $system);
        $this->assertStringContainsString('Never answer for the owner about their own requests', $system);
    }
}
