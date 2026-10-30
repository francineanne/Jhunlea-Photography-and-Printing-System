<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantAuthor;
use App\Enums\AssistantFeature;
use App\Enums\AssistantIntent;
use App\Enums\AssistantOutcome;
use App\Models\PhotographyPackage;
use App\Services\Assistant\PriceGuard;
use App\Services\Assistant\Providers\ProviderMessage;
use App\Services\Assistant\Providers\ProviderRequest;
use App\Services\Assistant\ThreadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** No peso amount reaches a reply unless the shop's knowledge or the conversation already had it. */
class PriceGuardTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    private const UNVERIFIED = 'Pasensya na po, hindi ako sigurado sa eksaktong presyo. Please message the studio for the exact price.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    private function request(string $system, string ...$userTexts): ProviderRequest
    {
        return new ProviderRequest($system, array_map(fn ($text) => new ProviderMessage(ProviderMessage::USER, $text), $userTexts), AssistantFeature::AskLumi);
    }

    public static function amountForms(): array
    {
        return [
            'peso sign with comma' => ['Only ₱1,500 today', ['1500']],
            'peso sign with decimals' => ['Price: ₱1500.00', ['1500']],
            'peso sign with comma and decimals' => ['From ₱1,500.00 per session', ['1500']],
            'PHP before' => ['PHP 1,500 lang', ['1500']],
            'Php attached' => ['Php1500 po', ['1500']],
            'php lowercase before' => ['php 250 per page', ['250']],
            'capital P' => ['P1,500 only', ['1500']],
            'capital P with space' => ['P 999.50', ['999.5']],
            'pesos after' => ['1,500 pesos', ['1500']],
            'piso after' => ['1500 piso', ['1500']],
            'php after' => ['1,500 php', ['1500']],
            'range both signs' => ['₱500-₱1,000', ['500', '1000']],
            'range spaced one sign' => ['₱500 - 1,000', ['500', '1000']],
            'range with to and pesos' => ['500 to 1,000 pesos', ['500', '1000']],
            'range with hanggang' => ['₱500 hanggang ₱800', ['500', '800']],
            'centavos kept' => ['₱12.50 per page', ['12.5']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('amountForms')]
    public function test_amounts_are_recognized_and_normalized(string $text, array $expected): void
    {
        $this->assertEqualsCanonicalizing($expected, app(PriceGuard::class)->amounts($text));
    }

    public static function notAmounts(): array
    {
        return [
            'quantity' => ['I need 20 copies and 3 sets'],
            'date' => ['Pwede sa 2026-11-10?'],
            'time' => ['Open from 9:00 AM to 6:00 PM'],
            'phone placeholder' => ['Call [phone] or 0917'],
            'id' => ['package #12 and PR-000123'],
            'people' => ['Up to 5 people, 60 minutes, 20 digital photos'],
        ];
    }

    #[DataProvider('notAmounts')]
    public function test_numbers_without_a_peso_marker_are_not_amounts(string $text): void
    {
        $this->assertSame([], app(PriceGuard::class)->amounts($text));
    }

    public function test_the_same_amount_written_three_ways_is_one_amount(): void
    {
        $guard = app(PriceGuard::class);

        $this->assertSame($guard->amounts('₱1,500'), $guard->amounts('PHP 1500.00'));
        $this->assertSame($guard->amounts('₱1,500'), $guard->amounts('1500 pesos'));
    }

    public function test_an_invented_price_is_removed_and_the_rest_is_kept(): void
    {
        $reply = "Maganda ang Wedding package! Ito ay ₱9,999 lang ngayon. Kasama ang dalawang photographer.\n- Extra hour: ₱500\n- Prints included";

        $guarded = app(PriceGuard::class)->guard($reply, $this->request('Price: ₱25,000.00'));

        $this->assertSame("Maganda ang Wedding package! Kasama ang dalawang photographer.\n- Prints included", $guarded);
    }

    public function test_prices_from_the_shop_knowledge_and_from_the_customer_are_kept(): void
    {
        $reply = 'The package is ₱25,000. You said your budget is 30,000 pesos, so it fits.';

        $this->assertSame($reply, app(PriceGuard::class)->guard($reply, $this->request('Price: ₱25,000.00', 'Budget ko ay P30,000')));
    }

    public function test_amounts_in_model_role_messages_are_not_trusted(): void
    {
        $request = new ProviderRequest('No prices here', [
            new ProviderMessage(ProviderMessage::USER, 'Magkano?'),
            new ProviderMessage(ProviderMessage::MODEL, 'It is ₱700.'),
            new ProviderMessage(ProviderMessage::USER, 'Sure?'),
        ], AssistantFeature::AskLumi);

        $this->assertSame(self::UNVERIFIED, app(PriceGuard::class)->guard('Yes, it is ₱700.', $request));
    }

    public function test_a_reply_without_amounts_is_unchanged(): void
    {
        $reply = "Bukas kami 9:00 AM. Pwede ang 20 copies sa 2026-11-10!\n\nTawag lang sa [phone].";

        $this->assertSame($reply, app(PriceGuard::class)->guard($reply, $this->request('Nothing')));
    }

    public function test_when_every_sentence_has_an_invented_price_the_fixed_text_is_used_and_intent_and_cards_stay(): void
    {
        $package = PhotographyPackage::factory()->published()->create(['price' => '2500.00']);
        $this->fake->push(self::reply('recommend', 'This is ₱1,999 only! Promo price ₱1,500.', [['type' => 'package', 'id' => $package->id]]));

        $result = $this->ask('Magkano?');

        $this->assertSame(self::UNVERIFIED, $result->reply);
        $this->assertSame(AssistantIntent::Recommend, $result->intent);
        $this->assertSame(AssistantOutcome::Answered, $result->outcome);
        $this->assertSame([$package->id], array_column($result->cards, 'id'));
        $this->assertSame($package->priceText(), $result->cards[0]['price']);
    }

    public function test_the_engine_keeps_a_price_from_the_catalog_and_strips_an_invented_one(): void
    {
        PhotographyPackage::factory()->published()->create(['name' => 'Wedding Classic', 'price' => '25000.00']);
        $this->fake->push(self::reply('answer', 'Ang Wedding Classic ay ₱25,000. May discount na ₱5,000 kung ngayon ka mag-book.'));

        $this->assertSame('Ang Wedding Classic ay ₱25,000.', $this->ask('Magkano ang wedding?')->reply);
    }

    public function test_handoff_replies_are_guarded_too_and_still_need_the_owner(): void
    {
        $this->fake->push(self::reply('handoff', 'The custom quote is ₱3,000. I will ask the studio.'));

        $result = $this->ask('Custom quote please');

        $this->assertSame('I will ask the studio.', $result->reply);
        $this->assertTrue($result->needsOwner);
    }

    public function test_owner_assist_keeps_the_threads_own_amounts(): void
    {
        $history = [
            new ThreadMessage(AssistantAuthor::Customer, 'Is 1,200 pesos ok for 100 prints?'),
            new ThreadMessage(AssistantAuthor::Owner, 'Yes, ₱1,200 for 100 prints.'),
        ];

        $this->fake->push(self::reply('answer', 'Yes, ₱1,200 for 100 prints is confirmed by the studio. Delivery is ₱150.'));
        $this->assertSame('Yes, ₱1,200 for 100 prints is confirmed by the studio.', $this->ask('When can I pick up?', AssistantFeature::OwnerAssistSuggest, history: $history)->reply);

        $this->fake->push(self::reply('answer', "- Customer wants 100 prints for ₱1,200\n- Rush fee of ₱300 offered\n- Pickup date still open"));
        $this->assertSame("- Customer wants 100 prints for ₱1,200\n- Pickup date still open", $this->ask('When can I pick up?', AssistantFeature::OwnerAssistSummarize, history: $history)->reply);
    }

    public function test_studio_away_is_guarded(): void
    {
        $this->fake->push(self::reply('answer', 'The owner is away. Rush printing is ₱99 extra.'));

        $this->assertSame('The owner is away.', $this->ask('Rush?', AssistantFeature::StudioAway)->reply);
    }

    public function test_off_topic_still_uses_the_fixed_refusal(): void
    {
        $this->fake->push(self::reply('off_topic', 'Bitcoin is ₱3,000,000 today.'));

        $this->assertSame(config('assistant.messages.off_topic'), $this->ask('Bitcoin price?')->reply);
    }
}
