<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sending a message (Phase 7B): validation, plain text, idempotency, and what a message may be "about". */
class SendMessageTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

    public function test_a_message_is_stored_and_returned(): void
    {
        $customer = $this->customer(['name' => 'Ana Santos']);
        $studio = $this->studio($customer);

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Hello po!', 'client_id' => 'c-1'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Hello po!')
            ->assertJsonPath('message.sender_type', 'customer')
            ->assertJsonPath('message.sender_label', 'Ana Santos')
            ->assertJsonPath('message.mine', true)
            ->assertJsonStructure(['message' => ['id', 'created_at' => ['iso', 'local']], 'state' => ['studio' => ['owner_away', 'away_notice', 'waiting_for_studio', 'needs_owner'], 'unread_count', 'poll', 'has_more_before']]);

        $studio->refresh();
        $this->assertSame('Hello po!', $studio->last_message_preview);
        $this->assertSame('customer', $studio->last_sender_type->value);
        $this->assertNotNull($studio->last_message_at);
    }

    public function test_empty_whitespace_and_too_long_messages_are_refused(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);

        foreach (['', "   \n\t ", "\x01\x02"] as $body) {
            $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => $body])->assertStatus(422)->assertJsonStructure(['code', 'message']);
        }

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => str_repeat('a', 1001)])
            ->assertStatus(422)->assertJson(['code' => 'invalid_message']);
        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => str_repeat('é', 1000)])->assertCreated();

        $this->assertSame(1, ChatMessage::query()->count());
    }

    public function test_control_characters_are_stripped_and_newlines_normalized(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => "Line one\r\nLine\x07 two\rLine three\x00"])->assertCreated();

        $this->assertSame("Line one\nLine two\nLine three", ChatMessage::query()->value('body'));
    }

    public function test_html_is_stored_and_returned_as_the_literal_text(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => '<script>alert(1)</script>'])
            ->assertCreated()->assertJsonPath('message.body', '<script>alert(1)</script>');

        $this->assertSame('<script>alert(1)</script>', ChatMessage::query()->value('body'));
        $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertJsonPath('messages.0.body', '<script>alert(1)</script>');
    }

    public function test_a_retried_send_with_the_same_client_id_is_stored_once(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);

        $first = $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Once', 'client_id' => 'abc-123'])->assertCreated();
        $second = $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Once', 'client_id' => 'abc-123'])->assertOk();

        $this->assertSame($first->json('message.id'), $second->json('message.id'));
        $this->assertSame(1, ChatMessage::query()->count());

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'x', 'client_id' => 'bad id with spaces'])->assertStatus(422);
    }

    public function test_about_accepts_only_the_customers_own_order_or_booking(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $studio = $this->studio($customer);
        $order = PrintingOrder::factory()->forCustomer($customer)->create(['reference_number' => 'PR-000777']);
        $booking = PhotographyBooking::factory()->forCustomer($customer)->create();
        $foreign = PrintingOrder::factory()->forCustomer($other)->create();

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'About my order', 'about' => ['type' => 'printing_order', 'id' => $order->id]])
            ->assertCreated()->assertJsonPath('message.about', ['type' => 'printing_order', 'id' => $order->id, 'reference' => 'PR-000777']);
        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'About my shoot', 'about' => ['type' => 'photography_booking', 'id' => $booking->id]])->assertCreated();

        foreach ([['type' => 'printing_order', 'id' => $foreign->id], ['type' => 'printing_order', 'id' => 999999], ['type' => 'user', 'id' => $customer->id]] as $about) {
            $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'x', 'about' => $about])->assertStatus(422)->assertJson(['code' => 'invalid_about']);
        }

        $this->assertSame(2, ChatMessage::query()->count());
    }

    public function test_from_assistant_conversation_must_be_the_customers_own_and_go_to_the_studio(): void
    {
        $customer = $this->customer();
        $mine = $this->askLumi($customer);
        $theirs = $this->askLumi($this->customer());
        $studio = $this->studio($customer);

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'From Lumi', 'from_assistant_conversation' => $mine->id])
            ->assertCreated()->assertJsonPath('message.from_assistant_conversation', $mine->id)->assertJsonPath('message.via_lumi', true);
        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'x', 'from_assistant_conversation' => $theirs->id])->assertStatus(422);
        $this->actingAs($customer)->postJson(route('customer.messages.store', $mine), ['body' => 'x', 'from_assistant_conversation' => $mine->id])->assertStatus(422);
    }

    public function test_the_handoff_draft_uses_the_customers_last_three_questions(): void
    {
        $customer = $this->customer();
        $lumi = $this->askLumi($customer);

        foreach (['First question', 'Second   question', 'Third question', 'Fourth question'] as $body) {
            $this->say($lumi, $customer, $body);
        }

        $this->actingAs($customer)->getJson(route('customer.assistant.handoff-draft', $lumi))->assertOk()
            ->assertJson(['draft' => "Hi, I was asking Lumi about:\n- Second question\n- Third question\n- Fourth question", 'from_assistant_conversation' => $lumi->id]);

        $this->actingAs($customer)->getJson(route('customer.assistant.handoff-draft', $this->studio($customer)))->assertNotFound();
    }
}
