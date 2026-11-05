<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantAuthor;
use App\Enums\AssistantFeature;
use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use App\Services\Assistant\Providers\GeminiProvider;
use App\Services\Assistant\Providers\ProviderMessage;
use App\Services\Assistant\Providers\ProviderRequest;
use App\Services\Assistant\ThreadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * A smaller prompt (Phase 7E-2a): only the details of the items that match the message (with Taglish synonyms), the
 * index of everything else, and a split into a stable prefix and a volatile tail that Gemini still gets as one text.
 */
class PromptTrimTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    private PhotographyPackage $wedding;

    private PhotographyPackage $christening;

    private PrintingService $tarpaulin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();

        $this->wedding = PhotographyPackage::factory()->published()->create(['name' => 'Wedding Classic', 'short_description' => 'Ceremony and reception coverage.']);
        $this->christening = PhotographyPackage::factory()->published()->create(['name' => 'Christening Day', 'short_description' => 'Baptism and family photos.']);
        $this->tarpaulin = PrintingService::factory()->published()->create(['name' => 'Tarpaulin Printing', 'description' => 'Large banners for events.']);
        PrintingService::factory()->published()->count(6)->create(['description' => 'Plain document printing.']);
    }

    private function system(string $message, array $history = []): string
    {
        $this->ask($message, history: $history);

        return $this->fake->lastRequest()->systemInstruction;
    }

    public function test_only_matching_details_are_sent_and_the_index_lists_everything(): void
    {
        $system = $this->system('Magkano ang Wedding Classic?');

        $this->assertStringContainsString("[package #{$this->wedding->id}] Wedding Classic", $system);
        $this->assertStringNotContainsString("[package #{$this->christening->id}]", $system);
        $this->assertStringNotContainsString("[service #{$this->tarpaulin->id}]", $system);

        // The index still names every published item, so any of them can be recommended
        $this->assertStringContainsString("package #{$this->christening->id} Christening Day", $system);
        $this->assertStringContainsString("service #{$this->tarpaulin->id} Tarpaulin Printing", $system);
    }

    public function test_taglish_words_find_the_catalog_item(): void
    {
        $this->assertStringContainsString("[package #{$this->wedding->id}] Wedding Classic", $this->system('May package ba kayo para sa kasal?'));
        $this->assertStringContainsString("[package #{$this->christening->id}] Christening Day", $this->system('Pa-quote po sa binyag'));
        $this->assertStringContainsString("[service #{$this->tarpaulin->id}] Tarpaulin Printing", $this->system('pa-print ng tarp'));

        // The synonyms only steer the ranking: the customer's own words are what the model reads
        $this->assertSame('pa-print ng tarp', $this->fake->lastRequest()->messages[0]->text);
    }

    public function test_a_plural_finds_the_singular(): void
    {
        $system = $this->system('What packages do you have?');

        $this->assertStringContainsString("[package #{$this->wedding->id}]", $system);
        $this->assertStringContainsString("[package #{$this->christening->id}]", $system);
    }

    public function test_a_follow_up_keeps_the_topic_of_the_conversation(): void
    {
        $history = [
            new ThreadMessage(AssistantAuthor::Customer, 'Magkano ang Christening Day package?'),
            new ThreadMessage(AssistantAuthor::Lumi, 'The Christening Day package covers the baptism and family photos.'),
        ];

        $this->assertStringContainsString("[package #{$this->christening->id}] Christening Day", $this->system('How about for 10 people?', $history));
    }

    public function test_at_most_the_configured_number_of_details_and_a_clear_line_when_nothing_matches(): void
    {
        config(['assistant.detail_items' => 2]);
        $system = $this->system('document printing');
        $this->assertSame(2, substr_count($system, '[service #'));

        $none = $this->system('Zyxwv qwerty');
        $this->assertStringContainsString('DETAILS: no item or FAQ matched this message.', $none);
        $this->assertStringNotContainsString('[service #', $none);
        $this->assertStringNotContainsString('[package #', $none);
    }

    public function test_the_stable_prefix_holds_the_rules_and_fixed_knowledge_and_nothing_per_message(): void
    {
        $this->ask('Magkano ang Wedding Classic?');
        $request = $this->fake->lastRequest();

        foreach (['You are Lumi', 'MODE: You are chatting with a customer', 'PAYMENT RULES', 'OPENING HOURS', 'WHERE TO FIND THINGS', 'CATALOG INDEX ('] as $part) {
            $this->assertStringContainsString($part, $request->stableSystem, $part);
        }

        $this->assertStringNotContainsString('Wedding Classic (', $request->stableSystem, 'No item details in the prefix');
        $this->assertStringNotContainsString('DETAILS', $request->stableSystem);
        $this->assertStringContainsString("[package #{$this->wedding->id}] Wedding Classic", $request->volatileSystem);
        $this->assertSame($request->stableSystem."\n\n".$request->volatileSystem, $request->systemInstruction);
    }

    public function test_gemini_gets_the_same_full_text_in_one_block(): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => self::reply('answer', 'Hi po')]]]]]])]);

        $request = new ProviderRequest('Stable part', [new ProviderMessage(ProviderMessage::USER, 'Hello')], AssistantFeature::AskLumi, 'Volatile part');
        (new GeminiProvider('gemini-test-key', 'gemini-test-model', 12, 500, 0.2))->generate($request);

        Http::assertSent(fn (Request $sent) => $sent->data()['systemInstruction']['parts'] === [['text' => "Stable part\n\nVolatile part"]]
            && ! str_contains($sent->body(), 'cache_control'));
    }
}
