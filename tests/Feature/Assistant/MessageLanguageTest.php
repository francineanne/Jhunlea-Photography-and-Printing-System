<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantAuthor;
use App\Enums\AssistantFeature;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\Eval\LanguageDetector;
use App\Services\Assistant\PromptBuilder;
use App\Services\Assistant\ThreadMessage;
use App\Support\MessageLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * The language of the LATEST message decides the reply's language (Phase 7E-2d). The second real eval answered an
 * English question in Filipino because the history was Filipino; the prompt now names the detected language.
 */
class MessageLanguageTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    public function test_the_detector(): void
    {
        $this->assertSame('en', MessageLanguage::detect('The one for document printing, is it ready?'));
        $this->assertSame('en', MessageLanguage::detect('How much is the Family Portrait package?'));
        $this->assertSame('fil', MessageLanguage::detect('Kumusta na po ang order ko?'));
        $this->assertSame('fil', MessageLanguage::detect('magkano grad pics nyo?'));
        $this->assertSame('taglish', MessageLanguage::detect('Pwede ba ako mag-book for this Saturday? Gusto ko sana the Family Portrait.'));

        foreach (['hi', 'ok po', '📸📸', '3500', '', 'Graduation Portraits'] as $short) {
            $this->assertSame('unknown', MessageLanguage::detect($short), "[{$short}]");
        }

        // The eval's reply check keeps its old answer for a reply it cannot read: English
        $this->assertSame('en', app(LanguageDetector::class)->detect('₱3,500.00'));
        $this->assertSame('fil', app(LanguageDetector::class)->detect('Opo, ready na po ang order ninyo.'));
    }

    public function test_the_line_follows_the_latest_message_not_the_filipino_history(): void
    {
        $customer = User::factory()->create();
        $history = [
            new ThreadMessage(AssistantAuthor::Customer, 'Kumusta na po ang order ko?'),
            new ThreadMessage(AssistantAuthor::Lumi, 'Alin po sa dalawa ninyong order ang tinutukoy ninyo?'),
        ];

        $this->ask('The one for document printing, is it ready?', actor: AssistantActor::user($customer->id), history: $history);
        $request = $this->fake->lastRequest();

        $this->assertStringContainsString('LANGUAGE OF THE LATEST CUSTOMER MESSAGE (detected by the system): English. Reply in English.', $request->volatileSystem);
        $this->assertStringNotContainsString('LANGUAGE OF THE LATEST CUSTOMER MESSAGE', $request->stableSystem, 'Never in the cached prefix');
        $this->assertStringEndsWith(PromptBuilder::LATEST_ONLY, $request->systemInstruction);

        // And the other way round, in the studio thread
        $this->ask('Magkano po ang Family Portrait ngayon?', AssistantFeature::StudioAway, AssistantActor::user($customer->id), [new ThreadMessage(AssistantAuthor::Customer, 'What packages do you have?')]);
        $this->assertStringContainsString("Filipino or Taglish. Reply in Filipino or Taglish, matching the customer's mix.", $this->fake->lastRequest()->volatileSystem);
    }

    public function test_no_line_for_an_unknown_message_or_the_owner_assist(): void
    {
        $this->ask('📸📸📸');
        $this->assertStringNotContainsString('LANGUAGE OF THE LATEST', $this->fake->lastRequest()->systemInstruction);

        $owner = User::factory()->owner()->create();
        foreach ([AssistantFeature::OwnerAssistSuggest, AssistantFeature::OwnerAssistSummarize] as $feature) {
            $this->ask('How much is the Family Portrait package?', $feature, AssistantActor::user($owner->id));
            $this->assertStringNotContainsString('LANGUAGE OF THE LATEST', $this->fake->lastRequest()->systemInstruction, $feature->value);
        }
    }

    public function test_the_stable_rule_stays_and_the_prefix_did_not_grow(): void
    {
        $this->ask('How much is the Family Portrait package?');
        $stable = $this->fake->lastRequest()->stableSystem;

        $this->assertStringContainsString("Answer in the language of the customer's LATEST message", $stable);
        $this->assertStringContainsString('Earlier messages do not decide the language', $stable);
        $this->assertStringContainsString('follow the LANGUAGE line below when there is one', $stable);
    }
}
