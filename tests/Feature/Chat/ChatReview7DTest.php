<?php

namespace Tests\Feature\Chat;

use App\Enums\AssistantAuthor;
use App\Enums\AssistantFeature;
use App\Enums\MessageSender;
use App\Enums\OwnerPresenceMode;
use App\Models\AvailabilityRule;
use App\Models\ChatMessage;
use App\Models\Faq;
use App\Models\PhotographyPackage;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\PriceGuard;
use App\Services\Assistant\PromptBuilder;
use App\Services\Assistant\Providers\ProviderMessage;
use App\Services\Assistant\Providers\ProviderRequest;
use App\Services\Assistant\ThreadMessage;
use App\Services\Chat\LumiReplyService;
use App\Services\Chat\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** The fixes from the 7C review (Phase 7D, Task 0). */
class ChatReview7DTest extends TestCase
{
    use ChatHelpers, FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    /** Monday to Saturday 9:00 AM to 6:00 PM, Sunday closed. */
    private function weekHours(): void
    {
        foreach (range(0, 6) as $weekday) {
            $rule = new AvailabilityRule(['is_open' => $weekday !== 0, 'opens_at' => '09:00:00', 'closes_at' => '18:00:00']);
            $rule->weekday = $weekday;
            $rule->save();
        }
    }

    private function systemKinds($conversation): array
    {
        return $conversation->messages()->where('sender_type', MessageSender::System)->orderBy('id')->get()->map(fn (ChatMessage $m) => $m->systemKind()?->value)->all();
    }

    // 0a: one away indication at a time

    public function test_the_status_line_stops_repeating_the_away_notice_once_the_thread_shows_it(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Hello?');

        $before = $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertOk();
        $this->assertNotNull($before->json('state.studio.away_notice'), 'Nothing in the thread yet: the status line says it');

        $this->actingAs($customer)->postJson(route('customer.messages.lumi', $studio))->assertOk();
        $this->assertSame(['away_notice'], $this->systemKinds($studio));

        $after = $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertOk();
        $this->assertNull($after->json('state.studio.away_notice'), 'The thread says it now: the status line is quiet');
        $this->assertTrue($after->json('state.studio.owner_away'));
    }

    public function test_after_a_fallback_the_status_line_is_quiet_too(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.api_key' => null]);
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Hello?');

        $this->actingAs($customer)->postJson(route('customer.messages.lumi', $studio))->assertOk();

        $this->assertSame(['ai_unavailable'], $this->systemKinds($studio));
        $this->assertNull($this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->json('state.studio.away_notice'));
    }

    // 0b: no away notice before a fallback

    public function test_a_stored_fallback_gets_no_away_notice(): void
    {
        config(['assistant.enabled' => false]);
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Hello?');

        app(LumiReplyService::class)->reply($studio, $customer);

        $this->assertSame(['ai_unavailable'], $this->systemKinds($studio));
    }

    // 0c: fallback wording follows the opening hours

    public function test_the_studio_fallback_never_says_when_we_open_while_the_shop_is_open(): void
    {
        $this->weekHours();
        config(['assistant.provider' => 'gemini', 'assistant.api_key' => null]);
        $customer = $this->customer();

        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'Asia/Manila'));
        $open = $this->studio($customer);
        $this->say($open, $customer, 'Open now?');
        $openReply = app(LumiReplyService::class)->reply($open, $customer)->messages[0]->body;

        $this->travelTo(Carbon::parse('2026-11-10 20:00', 'Asia/Manila'));
        $this->ownerSays($open, $this->owner(), 'Hi');
        $this->presence(OwnerPresenceMode::Away);
        $this->say($open, $customer, 'Closed now?');
        $closedReply = app(LumiReplyService::class)->reply($open, $customer)->messages[0]->body;

        $this->assertSame(config('chat.messages.studio_fallback_open'), $openReply);
        $this->assertSame(config('chat.messages.studio_fallback_closed'), $closedReply);
        $this->assertStringNotContainsString('when we open', $openReply);
    }

    // 0d: English fallback with a way forward

    public function test_the_ask_lumi_fallback_is_english_and_offers_the_faq_and_the_studio(): void
    {
        config(['assistant.enabled' => false]);
        $customer = $this->customer();
        $ask = $this->askLumi($customer);
        $this->say($ask, $customer, 'Hello');

        $notice = app(LumiReplyService::class)->reply($ask, $customer)->messages[0];

        $this->assertSame('Lumi is taking a short break right now. You can browse the FAQ or message the studio.', $notice->body);
        $this->assertSame('ai_unavailable', $notice->systemKind()?->value);

        // Without a published question there is no FAQ to browse: only the studio is offered
        $this->actingAs($customer)->get(route('customer.assistant.page'))->assertOk()
            ->assertSee('data-chat-fallback-actions', false)->assertSee("switchTab('studio')", false)->assertDontSee('Browse the FAQ');

        Faq::factory()->create();
        $this->actingAs($customer)->get(route('customer.assistant.page'))->assertOk()->assertSee(route('faq'))->assertSee('Browse the FAQ');
    }

    // 0e: the "more after" loop has a hard stop

    public function test_the_refresh_loop_has_a_page_limit(): void
    {
        $this->assertMatchesRegularExpression('/export const MAX_REFRESH_PAGES = \d+;/', file_get_contents(resource_path('js/chat-common.js')));

        // Both polling loops, the customer's and the owner's, stop at that limit
        foreach (['js/chat.js', 'js/owner-chat.js'] as $file) {
            $this->assertStringContainsString('while (more && pages < MAX_REFRESH_PAGES)', file_get_contents(resource_path($file)), $file);
        }
    }

    // 0f: Retry and Discard on a failed bubble

    public function test_a_failed_bubble_offers_retry_and_discard(): void
    {
        $page = $this->actingAs($this->customer())->get(route('customer.assistant.page'))->assertOk();

        $page->assertSee('retryMessage(row.message)', false)->assertSee('discardMessage(row.message)', false)->assertSee('Discard');
        $this->assertStringContainsString('discardMessage(local)', file_get_contents(resource_path('js/chat.js')));
    }

    // 0g: "You", never the stored name

    public function test_own_messages_are_labelled_you_in_both_chats(): void
    {
        $customer = $this->customer(['name' => 'Ana Reyes']);
        $owner = $this->owner(['name' => 'Jhun']);
        $studio = $this->studio($customer);
        $ask = $this->askLumi($customer);
        $this->say($studio, $customer, 'Studio question');
        $this->say($ask, $customer, 'Lumi question');
        $this->ownerSays($studio, $owner, 'Owner answer');

        $labels = fn ($response) => collect($response->json('messages'))->mapWithKeys(fn ($m) => [$m['body'] => $m['sender_label']]);

        $customerStudio = $labels($this->actingAs($customer)->getJson(route('customer.messages.show', $studio)));
        $this->assertSame('You', $customerStudio['Studio question']);
        $this->assertSame('Jhun', $customerStudio['Owner answer']);
        $this->assertSame('You', $labels($this->actingAs($customer)->getJson(route('customer.messages.show', $ask)))['Lumi question']);

        $ownerStudio = $labels($this->actingAs($owner)->getJson(route('owner.messages.show', $studio)));
        $this->assertSame('You', $ownerStudio['Owner answer']);
        $this->assertSame('Ana Reyes', $ownerStudio['Studio question']);
    }

    // 0h: no cards with handoff, clarify or off_topic

    public function test_cards_only_come_with_answer_or_recommend(): void
    {
        $package = PhotographyPackage::factory()->published()->create();
        $customer = $this->customer();
        $ask = $this->askLumi($customer);

        foreach (['handoff' => 0, 'clarify' => 0, 'off_topic' => 0, 'answer' => 1, 'recommend' => 1] as $intent => $cards) {
            $this->fake->push(self::reply($intent, "Reply for {$intent}.", [['type' => 'package', 'id' => $package->id]]));
            $this->say($ask, $customer, "Question {$intent}");

            $reply = app(LumiReplyService::class)->reply($ask, $customer)->messages[0];

            $this->assertCount($cards, $reply->meta['recommend'] ?? [], $intent);
        }
    }

    // 0i: only the latest message

    public function test_the_prompt_ends_with_the_latest_message_rule(): void
    {
        $customer = $this->customer();
        $ask = $this->askLumi($customer);
        $this->say($ask, $customer, 'First about weddings');
        app(LumiReplyService::class)->reply($ask, $customer);
        $this->say($ask, $customer, 'Now about printing');
        app(LumiReplyService::class)->reply($ask, $customer);

        $request = $this->fake->lastRequest();

        $this->assertStringEndsWith(PromptBuilder::LATEST_ONLY, $request->systemInstruction);
        $this->assertStringContainsString('Do not bring back an earlier topic', PromptBuilder::LATEST_ONLY);
        $this->assertStringContainsString('only when they answer the latest message', $request->systemInstruction);
        $this->assertSame('Now about printing', last($request->messages)->text);
        $this->assertSame(ProviderMessage::USER, last($request->messages)->role);
    }

    // 0j: a handoff reply offers "Contact the owner"

    public function test_a_handoff_reply_offers_contact_the_owner(): void
    {
        $customer = $this->customer();
        $ask = $this->askLumi($customer);
        $this->say($ask, $customer, 'I want a person');
        $this->fake->push(self::reply('handoff', 'Let me hand this to the studio.'));
        app(LumiReplyService::class)->reply($ask, $customer);

        $this->actingAs($customer)->getJson(route('customer.messages.show', $ask))->assertOk()->assertJsonPath('messages.2.intent', 'handoff');
        $this->actingAs($customer)->get(route('customer.assistant.page'))->assertOk()
            ->assertSee('data-chat-handoff', false)->assertSee("row.message.intent === 'handoff'", false)->assertSee('contactOwner()', false);

        // The button's action: the draft for the studio thread, nothing sent
        $this->actingAs($customer)->getJson(route('customer.assistant.handoff-draft', $ask))->assertOk()->assertJsonPath('from_assistant_conversation', $ask->id);
        $this->assertSame(0, $this->studio($customer)->messages()->count());
    }

    // 0k: refused questions stay out of the draft

    public function test_the_handoff_draft_leaves_out_questions_lumi_refused(): void
    {
        $customer = $this->customer();
        $ask = $this->askLumi($customer);

        foreach ([['How much is a wedding shoot?', 'answer'], ['What is the capital of France?', 'off_topic'], ['Can I bring my dog?', 'answer'], ['Tell me a joke', 'off_topic']] as [$question, $intent]) {
            $this->say($ask, $customer, $question);
            $this->fake->push(self::reply($intent, 'Reply.'));
            app(LumiReplyService::class)->reply($ask, $customer);
        }

        $draft = app(MessageService::class)->handoffDraft($ask);

        $this->assertStringContainsString('How much is a wedding shoot?', $draft);
        $this->assertStringContainsString('Can I bring my dog?', $draft);
        $this->assertStringNotContainsString('capital of France', $draft);
        $this->assertStringNotContainsString('joke', $draft);
    }

    // 0l: where to find things, by page name

    public function test_the_knowledge_says_where_things_are_without_inventing_addresses(): void
    {
        $customer = $this->customer();
        $ask = $this->askLumi($customer);
        $this->say($ask, $customer, 'Where can I see my orders?');
        app(LumiReplyService::class)->reply($ask, $customer);

        $system = $this->fake->lastRequest()->systemInstruction;
        $section = str($system)->after('WHERE TO FIND THINGS')->before('CATALOG INDEX')->toString();

        foreach (['My orders', 'notification bell', 'Book a photoshoot', 'Request printing', 'Messages'] as $page) {
            $this->assertStringContainsString($page, $section);
        }

        $this->assertDoesNotMatchRegularExpression('~https?://|www\.|/my/|\.com~i', $section);
        $this->assertStringContainsString('Never write a web address', $system);
    }

    // 0m: a 429 reads as a friendly sentence

    public function test_a_throttled_chat_request_answers_with_friendly_text(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($customer)->postJson(route('customer.messages.read', $studio));
        }

        $response = $this->actingAs($customer)->postJson(route('customer.messages.read', $studio))->assertStatus(429);
        $response->assertJson(['code' => 'rate_limited', 'message' => config('chat.messages.rate_limited')]);
        $this->assertStringNotContainsString('Too Many Attempts', $response->getContent());

        $this->assertStringContainsString('status === 429 && !data?.code', file_get_contents(resource_path('js/chat.js')));
    }

    // 0n: the owner's own amounts are trusted, invented ones are not

    public function test_price_guard_keeps_an_amount_the_owner_stated_in_the_thread(): void
    {
        $request = new ProviderRequest('No prices in the knowledge', [
            new ProviderMessage(ProviderMessage::USER, 'How much for the rush?'),
            new ProviderMessage(ProviderMessage::MODEL, '(Studio owner) Rush printing is ₱150 extra.', fromOwner: true),
            new ProviderMessage(ProviderMessage::MODEL, 'Lumi said ₱999 before.'),
            new ProviderMessage(ProviderMessage::USER, 'So how much?'),
        ], AssistantFeature::StudioAway);

        $guarded = app(PriceGuard::class)->guard('The rush fee is ₱150. A same-day option is ₱999.', $request);

        $this->assertSame('The rush fee is ₱150.', $guarded);
    }

    public function test_the_studio_thread_sends_the_owners_words_as_owner_turns(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Rush fee?');
        $this->ownerSays($studio, $this->owner(), 'Rush printing is ₱150 extra.');
        $this->travel(16)->minutes();
        $this->say($studio, $customer, 'Ok, and the rush fee again?');
        $this->fake->push(self::reply('answer', 'The rush fee is ₱150. Delivery is ₱80.'));

        $reply = app(LumiReplyService::class)->reply($studio, $customer);

        $owner = collect($this->fake->lastRequest()->messages)->firstWhere('fromOwner', true);
        $this->assertNotNull($owner);
        $this->assertStringContainsString('₱150', $owner->text);
        $this->assertSame('The rush fee is ₱150.', last($reply->messages)->body);
    }

    public function test_owner_assist_transcript_trusts_the_owners_amounts(): void
    {
        $request = app(PromptBuilder::class)->build(new AssistantInput(
            AssistantFeature::OwnerAssistSuggest,
            AssistantActor::user(1),
            'So the rush is how much?',
            [new ThreadMessage(AssistantAuthor::Owner, 'Rush printing is ₱150 extra.')],
        ));

        $this->assertSame('Yes, ₱150 for rush.', app(PriceGuard::class)->guard('Yes, ₱150 for rush. Plus ₱20 handling.', $request));
    }
}
