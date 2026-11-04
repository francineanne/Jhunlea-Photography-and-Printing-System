<?php

namespace Tests\Feature\Chat;

use App\Enums\BookingStatus;
use App\Enums\MessageSender;
use App\Enums\OwnerPresenceMode;
use App\Enums\PrintingOrderStatus;
use App\Models\ChatMessage;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Services\Assistant\AssistantActor;
use App\Services\Chat\LumiReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * Cards for the customer's own orders and bookings, and the record a studio handoff is about (Phase 7E-1): built from
 * the database for the conversation's customer only, so a forged or foreign id never shows anyone else's record.
 */
class RecordCardsTest extends TestCase
{
    use ChatHelpers, FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    public function test_lumi_shows_a_card_for_the_customers_own_order_and_booking(): void
    {
        $customer = $this->customer();
        $order = PrintingOrder::factory()->forCustomer($customer)->status(PrintingOrderStatus::Accepted)->create(['reference_number' => 'PR-000123', 'confirmed_price' => '450.00']);
        $booking = PhotographyBooking::factory()->forCustomer($customer)->status(BookingStatus::Confirmed)->at(Carbon::now('UTC')->addDays(6)->setTime(2, 0))->create(['reference_number' => 'PB-000045', 'confirmed_price' => '2500.00']);
        $conversation = $this->askLumi($customer);
        $this->say($conversation, $customer, 'Ano na status ng order at booking ko?');
        $this->fake->push(self::reply('answer', 'Here are your order and booking.', [['type' => 'order', 'id' => $order->id], ['type' => 'booking', 'id' => $booking->id]]));

        app(LumiReplyService::class)->reply($conversation, $customer);

        $cards = collect($this->actingAs($customer)->getJson(route('customer.messages.show', $conversation))->assertOk()->json('messages'))->last()['cards'];

        $this->assertSame([
            'type' => 'order', 'id' => $order->id, 'name' => 'PR-000123 · Sample service', 'price' => '₱450.00 to pay',
            'summary' => 'Status: Accepted', 'inclusions' => [], 'url' => route('customer.printing.show', $order),
        ], $cards[0]);
        $this->assertSame('booking', $cards[1]['type']);
        $this->assertSame('PB-000045 · Sample package', $cards[1]['name']);
        $this->assertStringStartsWith('Status: Confirmed. Confirmed session: ', $cards[1]['summary']);
        $this->assertSame(route('customer.bookings.show', $booking), $cards[1]['url']);

        // A paid record says so
        Payment::factory()->paying($order)->create();
        $cards = collect($this->actingAs($customer)->getJson(route('customer.messages.show', $conversation))->json('messages'))->last()['cards'];
        $this->assertSame('Paid', $cards[0]['price']);
    }

    public function test_a_foreign_forged_or_missing_record_is_dropped_silently(): void
    {
        $customer = $this->customer();
        $other = $this->customer(['name' => 'Bea Other']);
        $theirs = PrintingOrder::factory()->forCustomer($other)->create(['reference_number' => 'PR-000999', 'confirmed_price' => '9999.00']);
        $theirBooking = PhotographyBooking::factory()->forCustomer($other)->create(['reference_number' => 'PB-000999']);
        $conversation = $this->askLumi($customer);
        $this->say($conversation, $customer, 'Show order PR-000999');
        $this->fake->push(self::reply('answer', 'Here it is.', [['type' => 'order', 'id' => $theirs->id], ['type' => 'booking', 'id' => $theirBooking->id], ['type' => 'order', 'id' => 987654]]));

        app(LumiReplyService::class)->reply($conversation, $customer);

        $response = $this->actingAs($customer)->getJson(route('customer.messages.show', $conversation))->assertOk();
        $reply = collect($response->json('messages'))->last();
        $this->assertSame([], $reply['cards']);

        // Only the customer's own typed words name it; nothing the system added does
        $added = json_encode($reply, JSON_UNESCAPED_UNICODE);
        foreach (['PR-000999', 'PB-000999', '9,999', 'Bea Other'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $added);
        }
        $response->assertDontSee('PB-000999')->assertDontSee('9,999')->assertDontSee('Bea Other');
    }

    public function test_record_cards_only_on_an_answer_or_a_recommendation_and_never_for_a_guest(): void
    {
        $customer = $this->customer();
        $order = PrintingOrder::factory()->forCustomer($customer)->create();

        foreach (['clarify', 'handoff'] as $intent) {
            $this->fake->push(self::reply($intent, 'Which one po?', [['type' => 'order', 'id' => $order->id]]));
            $this->assertSame([], $this->ask('Order ko?', actor: AssistantActor::user($customer->id))->cards, $intent);
        }

        $this->fake->push(self::reply('recommend', 'Here.', [['type' => 'order', 'id' => $order->id]]));
        $this->assertCount(1, $this->ask('Order ko?', actor: AssistantActor::user($customer->id))->cards);

        $this->fake->push(self::reply('answer', 'Here.', [['type' => 'order', 'id' => $order->id]]));
        $this->assertSame([], $this->ask('Order ko?')->cards, 'A guest');
    }

    public function test_the_owner_sees_a_studio_card_linking_to_the_owners_page(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $order = PrintingOrder::factory()->forCustomer($customer)->create(['reference_number' => 'PR-000124']);
        $studio = $this->studio($customer);
        $this->presence(OwnerPresenceMode::Away);
        $this->say($studio, $customer, 'Status ng PR-000124?');
        $this->fake->push(self::reply('answer', 'Your order is under the shop\'s review list.', [['type' => 'order', 'id' => $order->id]]));

        app(LumiReplyService::class)->reply($studio, $customer);

        $owners = collect($this->actingAs($owner)->getJson(route('owner.messages.show', $studio))->assertOk()->json('messages'))->last()['cards'];
        $this->assertSame(route('owner.orders.show', $order), $owners[0]['url']);

        $customers = collect($this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertOk()->json('messages'))->last()['cards'];
        $this->assertSame(route('customer.printing.show', $order), $customers[0]['url']);
    }

    public function test_a_studio_handoff_stores_the_customers_own_record_for_the_owners_header(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $order = PrintingOrder::factory()->forCustomer($customer)->create(['reference_number' => 'PR-000125']);
        $studio = $this->studio($customer);
        $this->presence(OwnerPresenceMode::Away);

        // The model names the record
        $this->say($studio, $customer, 'I want to change the paper of my order');
        $this->fake->push(self::reply('handoff', 'The studio will help you with that.', [['type' => 'order', 'id' => $order->id]]));
        app(LumiReplyService::class)->reply($studio, $customer);

        $reply = ChatMessage::query()->where('sender_type', MessageSender::Assistant)->latest('id')->first();
        $this->assertSame(['type' => 'printing_order', 'id' => $order->id], $reply->meta['about']);
        $this->actingAs($owner)->getJson(route('owner.messages.show', $studio))->assertOk()
            ->assertJsonPath('messages.'.($studio->messages()->count() - 1).'.about.url', route('owner.orders.show', $order))
            ->assertJsonPath('messages.'.($studio->messages()->count() - 1).'.about.reference', 'PR-000125');
    }

    public function test_a_handoff_finds_the_reference_in_the_message_but_never_a_foreign_one(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $booking = PhotographyBooking::factory()->forCustomer($customer)->create(['reference_number' => 'PB-000126']);
        $theirs = PrintingOrder::factory()->forCustomer($other)->create(['reference_number' => 'PR-000998']);
        $studio = $this->studio($customer);
        $this->presence(OwnerPresenceMode::Away);

        $this->say($studio, $customer, 'Pwede ko bang ilipat ang PB-000126 sa Sabado?');
        $this->fake->push(self::reply('handoff', 'The studio will reply about that.'));
        app(LumiReplyService::class)->reply($studio, $customer);
        $this->assertSame(['type' => 'photography_booking', 'id' => $booking->id], ChatMessage::query()->where('sender_type', MessageSender::Assistant)->latest('id')->first()->meta['about']);

        // Someone else's reference, in the message or from the model, is never stored
        $studio->forceFill(['needs_owner' => false])->save();
        $this->say($studio, $customer, 'Cancel PR-000998 please');
        $this->fake->push(self::reply('handoff', 'The studio will reply about that.', [['type' => 'order', 'id' => $theirs->id]]));
        app(LumiReplyService::class)->reply($studio, $customer);
        $this->assertArrayNotHasKey('about', ChatMessage::query()->where('sender_type', MessageSender::Assistant)->latest('id')->first()->meta);
    }

    public function test_an_ask_lumi_handoff_stores_no_about_reference(): void
    {
        $customer = $this->customer();
        $order = PrintingOrder::factory()->forCustomer($customer)->create();
        $conversation = $this->askLumi($customer);
        $this->say($conversation, $customer, 'I need a person for my order');
        $this->fake->push(self::reply('handoff', 'You can contact the owner.', [['type' => 'order', 'id' => $order->id]]));

        app(LumiReplyService::class)->reply($conversation, $customer);

        $this->assertArrayNotHasKey('about', ChatMessage::query()->where('sender_type', MessageSender::Assistant)->latest('id')->first()->meta);
    }
}
