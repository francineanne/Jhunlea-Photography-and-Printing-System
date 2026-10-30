<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageSender;
use App\Enums\OwnerPresenceMode;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\PhotographyPackage;
use App\Models\User;
use App\Services\Assistant\Exceptions\ProviderRateLimited;
use App\Services\Assistant\Providers\FakeProvider;
use App\Services\Assistant\Providers\ProviderRequest;
use App\Services\Assistant\Providers\ProviderResponse;
use App\Services\Chat\LumiReply;
use App\Services\Chat\LumiReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** Lumi answering inside a conversation (Phase 7B), always through the FakeProvider. */
class LumiReplyTest extends TestCase
{
    use ChatHelpers, FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    private function lumi(Conversation $conversation, User $customer): LumiReply
    {
        return app(LumiReplyService::class)->reply($conversation, $customer);
    }

    public function test_lumi_always_answers_in_an_ask_lumi_conversation(): void
    {
        $this->presence(OwnerPresenceMode::Available);
        $customer = $this->customer();
        $conversation = $this->askLumi($customer);
        $this->say($conversation, $customer, 'Magkano ang print?');
        $this->fake->push(self::reply('answer', 'Starts at five pesos per page.'));

        $response = $this->actingAs($customer)->postJson(route('customer.messages.lumi', $conversation))->assertOk()
            ->assertJsonPath('status', 'replied')
            ->assertJsonPath('messages.0.sender_type', 'assistant')
            ->assertJsonPath('messages.0.sender_label', 'Lumi (AI)')
            ->assertJsonPath('messages.0.body', 'Starts at five pesos per page.')
            ->assertJsonPath('messages.0.intent', 'answer');

        $this->assertCount(1, $response->json('messages'));
        $this->assertSame(MessageSender::Assistant, $conversation->refresh()->last_sender_type);
    }

    public function test_in_the_studio_thread_lumi_answers_only_while_the_owner_is_away(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Are you open?');

        $this->presence(OwnerPresenceMode::Available);
        $this->assertSame(LumiReply::NOT_ALLOWED, $this->lumi($studio, $customer)->status);
        $this->assertSame([], $this->fake->requests);

        $this->presence(OwnerPresenceMode::Away);
        $reply = $this->lumi($studio, $customer);

        $this->assertSame(LumiReply::REPLIED, $reply->status);
        $this->assertSame(['away_notice', null], array_map(fn (ChatMessage $message) => $message->systemKind()?->value, $reply->messages));
        $this->assertSame([MessageSender::System, MessageSender::Assistant], array_map(fn (ChatMessage $message) => $message->sender_type, $reply->messages));
    }

    public function test_lumi_stays_quiet_while_the_thread_needs_the_owner(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $studio->forceFill(['needs_owner' => true])->save();
        $this->say($studio, $customer, 'Hello?');

        $this->assertSame(LumiReply::NOT_ALLOWED, $this->lumi($studio, $customer)->status);
        $this->assertSame(1, $studio->messages()->count());
    }

    public function test_the_away_notice_comes_once_per_away_stretch(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);

        foreach (['One', 'Two'] as $body) {
            $this->say($studio, $customer, $body);
            $this->lumi($studio, $customer);
        }

        $this->assertSame(1, $this->notices($studio, 'away_notice'));

        // The owner replies, leaves again, and a new stretch starts with its own notice
        $this->ownerSays($studio, $owner, 'Back for a minute');
        $this->travel(16)->minutes();
        $this->say($studio, $customer, 'Three');
        $this->lumi($studio, $customer);

        $this->assertSame(2, $this->notices($studio, 'away_notice'));
    }

    public function test_after_five_ai_replies_in_a_row_lumi_stops_with_a_notice(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);

        for ($i = 0; $i < 5; $i++) {
            $this->say($studio, $customer, "Question {$i}");
            $this->assertSame(LumiReply::REPLIED, $this->lumi($studio, $customer)->status);
        }

        $this->say($studio, $customer, 'Question 6');
        $reply = $this->lumi($studio, $customer);

        $this->assertSame('away_cap', $reply->messages[0]->systemKind()?->value);
        $this->assertSame(MessageSender::System, $reply->messages[0]->sender_type);
        $this->assertSame(5, $studio->messages()->where('sender_type', MessageSender::Assistant)->count());
        $this->assertCount(5, $this->fake->requests);
    }

    public function test_a_second_call_returns_the_existing_reply_instead_of_a_new_one(): void
    {
        $customer = $this->customer();
        $conversation = $this->askLumi($customer);
        $this->say($conversation, $customer, 'Hi');

        $first = $this->lumi($conversation, $customer);
        $second = $this->lumi($conversation, $customer);

        $this->assertSame(LumiReply::ALREADY_ANSWERED, $second->status);
        $this->assertSame($first->messages[0]->id, $second->messages[0]->id);
        $this->assertCount(1, $this->fake->requests);
        $this->assertSame(2, $conversation->messages()->where('sender_type', MessageSender::Assistant)->count(), 'The introduction and one reply');
    }

    public function test_nothing_to_do_without_a_customer_message(): void
    {
        $customer = $this->customer();

        $this->assertSame(LumiReply::NOTHING_TO_DO, $this->lumi($this->askLumi($customer), $customer)->status);
        $this->assertSame([], $this->fake->requests);
    }

    public function test_the_reply_is_discarded_when_the_owner_answered_while_the_model_was_working(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Is anyone there?');

        // The owner replies during the provider call
        $this->app->instance(FakeProvider::class, new class(fn () => $this->ownerSays($studio, $owner, 'Yes, I am here!')) extends FakeProvider
        {
            public function __construct(private readonly \Closure $during) {}

            public function generate(ProviderRequest $request): ProviderResponse
            {
                ($this->during)();

                return parent::generate($request);
            }
        });

        $reply = $this->lumi($studio, $customer);

        $this->assertSame(LumiReply::DISCARDED, $reply->status);
        $this->assertSame(0, $studio->messages()->whereIn('sender_type', [MessageSender::Assistant, MessageSender::System])->count());
        $this->assertSame(MessageSender::Owner, $studio->refresh()->last_sender_type);
    }

    public function test_handoff_flags_the_thread_for_the_owner_and_notifies_nobody_extra(): void
    {
        $customer = $this->customer();
        $this->owner();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'I have a complaint.');
        $notices = DatabaseNotification::query()->count();
        $this->fake->push(self::reply('handoff', 'I will let the studio know.'));

        $reply = $this->lumi($studio, $customer);

        $this->assertSame('handoff', $reply->messages[1]->meta['intent']);
        $this->assertTrue($studio->refresh()->needs_owner);
        $this->assertNotNull($studio->needs_owner_at);
        $this->assertSame($notices, DatabaseNotification::query()->count());

        // Waiting for the owner now: Lumi does not answer the next message
        $this->say($studio, $customer, 'Hello?');
        $this->assertSame(LumiReply::NOT_ALLOWED, $this->lumi($studio, $customer)->status);
    }

    public function test_engine_fallbacks_become_system_notices_never_lumis_words(): void
    {
        $customer = $this->customer();
        $conversation = $this->askLumi($customer);

        $cases = [
            'no key' => fn () => config(['assistant.provider' => 'gemini', 'assistant.api_key' => null]),
            '429' => fn () => $this->fake->push(new ProviderRateLimited(1)),
            'bad json' => fn () => $this->fake->push('not json'),
            'limit' => fn () => config(['assistant.limits.per_minute_per_user' => 0]),
        ];

        foreach ($cases as $name => $setUp) {
            $this->fakeAssistant();
            $setUp();
            $this->travel(2)->seconds();

            $this->say($conversation, $customer, "Question ({$name})");
            $reply = $this->lumi($conversation, $customer);

            $this->assertSame(LumiReply::REPLIED, $reply->status, $name);
            $this->assertSame(MessageSender::System, $reply->messages[0]->sender_type, $name);
            $this->assertSame('ai_unavailable', $reply->messages[0]->systemKind()?->value, $name);
            $this->assertContains($reply->messages[0]->body, [config('assistant.messages.unavailable'), config('assistant.messages.limit_user')], $name);
        }

        $this->assertSame(1, $conversation->messages()->where('sender_type', MessageSender::Assistant)->count(), 'Only the introduction');
    }

    public function test_stored_replies_hold_references_only_and_cards_are_rebuilt_from_the_catalog(): void
    {
        $customer = $this->customer();
        $conversation = $this->askLumi($customer);
        $package = PhotographyPackage::factory()->published()->create(['name' => 'Wedding Classic', 'price' => '25000.00']);
        $this->say($conversation, $customer, 'Wedding?');
        $this->fake->push(self::reply('recommend', 'Try this one.', [['type' => 'package', 'id' => $package->id]]));

        $reply = $this->lumi($conversation, $customer);
        $stored = ChatMessage::query()->find($reply->messages[0]->id);

        $this->assertSame(['intent' => 'recommend', 'outcome' => 'answered', 'recommend' => [['type' => 'package', 'id' => $package->id]]], $stored->meta);
        $this->assertStringNotContainsString('Wedding Classic', json_encode($stored->meta));
        $this->assertStringNotContainsString('25', json_encode($stored->meta));

        $card = $this->actingAs($customer)->getJson(route('customer.messages.show', $conversation))->json('messages.2.cards.0');
        $this->assertSame('Wedding Classic', $card['name']);
        $this->assertSame($package->priceText(), $card['price']);

        $package->forceFill(['price' => '27000.00'])->save();
        $this->assertStringContainsString('27,000.00', $this->actingAs($customer)->getJson(route('customer.messages.show', $conversation))->json('messages.2.cards.0.price'));

        $package->forceFill(['is_published' => false])->save();
        $this->assertSame([], $this->actingAs($customer)->getJson(route('customer.messages.show', $conversation))->json('messages.2.cards'));
    }

    public function test_the_prompt_holds_at_most_eight_earlier_messages_of_this_conversation_only(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $other = $this->customer(['name' => 'Zelda Outsider']);
        $this->say($this->studio($other), $other, 'Secret of another customer');
        $this->say($this->askLumi($customer), $customer, 'Text from my other Ask Lumi conversation');

        foreach (range(1, 12) as $i) {
            $sender = $i % 3 === 0 ? MessageSender::Owner : MessageSender::Customer;
            ChatMessage::factory()->from($sender, $sender === MessageSender::Owner ? $owner->id : $customer->id)->create(['conversation_id' => $studio->id, 'body' => sprintf('history-%02d', $i)]);
        }
        $this->rows($studio, 1, MessageSender::System, null, ['body' => 'system-notice-text', 'meta' => ['system_kind' => 'owner_joined']]);
        $this->say($studio, $customer, 'The newest question');

        $this->lumi($studio, $customer);
        $request = $this->fake->lastRequest();
        $prompt = $request->promptText();

        foreach (range(5, 12) as $i) {
            $this->assertStringContainsString(sprintf('history-%02d', $i), $prompt);
        }
        foreach (range(1, 4) as $i) {
            $this->assertStringNotContainsString(sprintf('history-%02d', $i), $prompt);
        }
        $this->assertStringContainsString('(Studio owner) history-06', $prompt);
        $this->assertStringNotContainsString('system-notice-text', $prompt);
        $this->assertStringNotContainsString('Secret of another customer', $prompt);
        $this->assertStringNotContainsString('Zelda', $prompt);
        $this->assertStringNotContainsString('other Ask Lumi conversation', $prompt);
        $this->assertStringContainsString('The newest question', $request->messages[array_key_last($request->messages)]->text);
    }

    private function notices(Conversation $conversation, string $kind): int
    {
        return $conversation->messages()->where('sender_type', MessageSender::System)->get()->filter(fn (ChatMessage $message) => $message->systemKind()?->value === $kind)->count();
    }
}
