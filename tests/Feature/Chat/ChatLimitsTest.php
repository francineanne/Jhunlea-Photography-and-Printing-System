<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageSender;
use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** Anti-spam limits only (Phase 7B): people's conversations have no length limit; an Ask Lumi conversation does. */
class ChatLimitsTest extends TestCase
{
    use ChatHelpers, FakesAssistant, RefreshDatabase;

    public function test_twenty_messages_a_minute_then_the_named_throttle_answers_429(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $owner = $this->owner();

        for ($i = 0; $i < 20; $i++) {
            // An owner reply now and then keeps the unanswered streak from ending the test early
            if ($i === 10) {
                $this->ownerSays($studio, $owner);
            }

            $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => "Message {$i}"])->assertCreated();
        }

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'One too many'])
            ->assertStatus(429)->assertJson(['code' => 'rate_limited']);

        $this->travel(61)->seconds();
        $this->ownerSays($studio, $owner);
        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Next minute'])->assertCreated();
    }

    public function test_the_daily_cap_counts_every_conversation_of_the_manila_day(): void
    {
        $customer = $this->customer();
        $lumi = $this->askLumi($customer);
        $studio = $this->studio($customer);

        // 500 customer messages today (Manila), in another conversation
        $this->travelTo(Carbon::parse('2026-11-10 15:00:00', 'UTC')); // 23:00 in Manila
        $this->rows($lumi, 500, MessageSender::Customer, $customer->id, ['created_at' => Carbon::parse('2026-11-09 16:30:00', 'UTC')]);

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Hello'])
            ->assertStatus(429)->assertJson(['code' => 'daily_cap', 'message' => config('chat.messages.daily_cap')]);

        // Midnight in Manila is 16:00 UTC: a new day
        $this->travelTo(Carbon::parse('2026-11-10 16:00:00', 'UTC'));
        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Hello'])->assertCreated();
    }

    public function test_twenty_unanswered_messages_in_a_row_wait_for_the_studio(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->rows($studio, 20, MessageSender::Customer, $customer->id);

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Hello?'])
            ->assertStatus(429)->assertJson(['code' => 'waiting_for_studio', 'message' => config('chat.messages.waiting_for_studio')]);
        $this->assertSame(20, ChatMessage::query()->count());

        // An owner reply resets the streak
        $this->ownerSays($studio, $this->owner());
        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Thanks!'])->assertCreated();
    }

    public function test_a_lumi_reply_also_resets_the_streak(): void
    {
        $this->fakeAssistant();
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->rows($studio, 20, MessageSender::Customer, $customer->id);

        // The owner is away (auto, never active), so Lumi answers the newest message
        $this->actingAs($customer)->postJson(route('customer.messages.lumi', $studio))->assertOk()->assertJson(['status' => 'replied']);
        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Okay'])->assertCreated();
    }

    public function test_an_ask_lumi_conversation_holds_sixty_messages(): void
    {
        $customer = $this->customer();
        $lumi = $this->askLumi($customer); // 1: Lumi's introduction
        $this->rows($lumi, 59, MessageSender::Customer, $customer->id);

        $this->actingAs($customer)->postJson(route('customer.messages.store', $lumi), ['body' => 'One more'])
            ->assertStatus(409)->assertJson(['code' => 'conversation_full', 'message' => config('chat.messages.conversation_full')]);
        $this->assertSame(60, $lumi->messages()->count());
    }

    public function test_the_studio_thread_has_no_length_limit(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);

        for ($round = 0; $round < 8; $round++) {
            $this->rows($studio, 19, MessageSender::Customer, $customer->id);
            $this->rows($studio, 1, MessageSender::Owner, $owner->id);
        }

        $this->assertSame(160, $studio->messages()->count());
        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Still talking'])->assertCreated();
    }
}
