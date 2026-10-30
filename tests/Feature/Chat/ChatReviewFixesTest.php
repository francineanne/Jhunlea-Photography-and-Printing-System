<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageSender;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Services\Chat\LumiReply;
use App\Services\Chat\LumiReplyService;
use App\Services\Chat\OwnerPresence;
use App\Services\Chat\ThreadReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** The backend fixes from the 7B review (Phase 7C, Task 0). */
class ChatReviewFixesTest extends TestCase
{
    use ChatHelpers, FakesAssistant, RefreshDatabase;

    private function presenceService(): OwnerPresence
    {
        return app(OwnerPresence::class);
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

    // 0a: presence comes from real activity

    public function test_background_polls_do_not_count_as_owner_activity(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $message = $this->say($studio, $customer);

        $this->actingAs($owner)->getJson(route('owner.messages.index', ['poll' => 1]))->assertOk();
        $this->assertNull($this->presenceService()->lastActiveAt());

        $this->actingAs($owner)->getJson(route('owner.messages.show', [$studio, 'after' => $message->id]))->assertOk();
        $this->actingAs($owner)->getJson(route('owner.messages.show', [$studio, 'before' => $message->id]))->assertOk();
        $this->assertNull($this->presenceService()->lastActiveAt());
        $this->assertTrue($this->presenceService()->isAway());
    }

    public function test_opening_replying_resolving_and_the_heartbeat_count_as_activity(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->say($studio, $customer);

        $actions = [
            'inbox' => fn () => $this->actingAs($owner)->getJson(route('owner.messages.index'))->assertOk(),
            'thread' => fn () => $this->actingAs($owner)->getJson(route('owner.messages.show', $studio))->assertOk(),
            'reply' => fn () => $this->actingAs($owner)->postJson(route('owner.messages.store', $studio), ['body' => 'Hello'])->assertCreated(),
            'resolve' => fn () => $this->actingAs($owner)->postJson(route('owner.messages.resolve', $studio))->assertOk(),
            'heartbeat' => fn () => $this->actingAs($owner)->postJson(route('owner.messages.presence.heartbeat'))->assertOk()->assertJson(['away' => false]),
        ];

        foreach ($actions as $name => $action) {
            $this->travel(16)->minutes();
            $this->assertTrue($this->presenceService()->isAway(), $name);
            $action();
            $this->assertFalse($this->presenceService()->isAway(), $name);
        }
    }

    public function test_the_heartbeat_is_for_the_owner_only_and_has_its_own_limit(): void
    {
        $owner = $this->owner();

        $this->actingAs($this->customer())->postJson(route('owner.messages.presence.heartbeat'))->assertForbidden();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($owner)->postJson(route('owner.messages.presence.heartbeat'))->assertOk();
        }

        $this->actingAs($owner)->postJson(route('owner.messages.presence.heartbeat'))->assertStatus(429)->assertJson(['code' => 'rate_limited']);
    }

    // 0b: the away notice while the shop is open

    public function test_the_away_notice_depends_on_whether_the_shop_is_open_now(): void
    {
        $this->weekHours();

        // Tuesday 10:00 AM: open now, so no "tomorrow"
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'Asia/Manila'));
        $this->assertSame(config('chat.messages.away_notice_open'), $this->presenceService()->awayNotice());

        // Tuesday 7:00 AM: before opening
        $this->travelTo(Carbon::parse('2026-11-10 07:00', 'Asia/Manila'));
        $this->assertStringContainsString('today at 9:00 AM', $this->presenceService()->awayNotice());

        // Tuesday 6:00 PM exactly: just closed
        $this->travelTo(Carbon::parse('2026-11-10 18:00', 'Asia/Manila'));
        $this->assertStringContainsString('tomorrow at 9:00 AM', $this->presenceService()->awayNotice());

        // Sunday noon: a closed day
        $this->travelTo(Carbon::parse('2026-11-15 12:00', 'Asia/Manila'));
        $this->assertStringContainsString('tomorrow at 9:00 AM', $this->presenceService()->awayNotice());

        // Tuesday 10:00 AM, but blocked: closed today, even inside the usual hours
        BlockedDate::query()->forceCreate(['date' => '2026-11-10', 'reason' => 'Holiday']);
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'Asia/Manila'));
        $this->assertStringContainsString('tomorrow at 9:00 AM', $this->presenceService()->awayNotice());

        // The AI-off wording comes first
        config(['assistant.enabled' => false]);
        $this->assertSame(config('chat.messages.owner_unavailable_ai_off'), $this->presenceService()->awayNotice());
    }

    // 0c: the cap counts the last 24 hours, and is announced once

    public function test_ai_replies_older_than_a_day_do_not_count_toward_the_cap(): void
    {
        $this->fakeAssistant();
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->rows($studio, 5, MessageSender::Assistant);

        $this->travel(25)->hours();
        $this->say($studio, $customer, 'I am back');

        $reply = app(LumiReplyService::class)->reply($studio, $customer);

        $this->assertSame(LumiReply::REPLIED, $reply->status);
        $this->assertSame(MessageSender::Assistant, last($reply->messages)->sender_type);
    }

    public function test_the_cap_notice_comes_once_per_stretch_and_the_engine_is_not_called_again(): void
    {
        $this->fakeAssistant();
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->rows($studio, 5, MessageSender::Assistant);

        $this->say($studio, $customer, 'Hello?');
        $first = app(LumiReplyService::class)->reply($studio, $customer);
        $this->assertSame('away_cap', $first->messages[0]->systemKind()?->value);

        $requests = count($this->fake->requests);
        $this->say($studio, $customer, 'Anyone?');
        $second = app(LumiReplyService::class)->reply($studio, $customer);

        $this->assertSame(LumiReply::NOT_ALLOWED, $second->status);
        $this->assertCount($requests, $this->fake->requests);
        $this->assertSame(1, $studio->messages()->where('sender_type', MessageSender::System)->get()->filter(fn (ChatMessage $message) => $message->systemKind()?->value === 'away_cap')->count());
    }

    // 0d: the double-click guard

    public function test_a_reply_already_running_answers_in_progress_without_a_second_model_call(): void
    {
        $this->fakeAssistant();
        $customer = $this->customer();
        $conversation = $this->askLumi($customer);
        $this->say($conversation, $customer, 'Hello');

        $held = Cache::lock(LumiReplyService::LOCK_PREFIX.$conversation->id, 30);
        $this->assertTrue($held->get());

        $this->actingAs($customer)->postJson(route('customer.messages.lumi', $conversation))->assertOk()->assertJson(['status' => 'in_progress']);
        $this->assertSame([], $this->fake->requests);

        $held->release();

        $this->actingAs($customer)->postJson(route('customer.messages.lumi', $conversation))->assertOk()->assertJson(['status' => 'replied']);
        $this->assertCount(1, $this->fake->requests);
    }

    public function test_the_lock_is_released_when_the_reply_is_done(): void
    {
        $this->fakeAssistant();
        $customer = $this->customer();
        $conversation = $this->askLumi($customer);
        $this->say($conversation, $customer, 'Hello');

        app(LumiReplyService::class)->reply($conversation, $customer);

        $lock = Cache::lock(LumiReplyService::LOCK_PREFIX.$conversation->id, 30);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    // 0e: new conversations per day

    public function test_twenty_new_ask_lumi_conversations_a_manila_day(): void
    {
        $customer = $this->customer();
        $this->travelTo(Carbon::parse('2026-11-10 15:00:00', 'UTC')); // 23:00 in Manila

        Conversation::factory()->count(20)->forCustomer($customer)->create(['created_at' => Carbon::parse('2026-11-09 16:30:00', 'UTC')]);

        $this->actingAs($customer)->postJson(route('customer.assistant.store'))
            ->assertStatus(429)->assertJson(['code' => 'too_many_conversations', 'message' => config('chat.messages.too_many_conversations')]);
        $this->assertSame(20, Conversation::query()->count());

        $this->travelTo(Carbon::parse('2026-11-10 16:00:00', 'UTC')); // midnight in Manila
        $this->actingAs($customer)->postJson(route('customer.assistant.store'))->assertCreated();
    }

    // 0f: has_more_after

    public function test_polling_says_when_more_new_messages_are_waiting(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->rows($studio, 120, MessageSender::Customer, $customer->id);
        $ids = $studio->messages()->orderBy('id')->pluck('id')->all();

        $first = $this->actingAs($customer)->getJson(route('customer.messages.show', [$studio, 'after' => $ids[9]]))->assertOk();
        $first->assertJsonPath('state.has_more_after', true)->assertJsonCount(50, 'messages');
        $this->assertSame($ids[10], $first->json('messages.0.id'));

        $second = $this->actingAs($customer)->getJson(route('customer.messages.show', [$studio, 'after' => $ids[59]]))->assertOk();
        $second->assertJsonPath('state.has_more_after', true)->assertJsonCount(50, 'messages');

        // Exactly one page left is not "more"
        $last = $this->actingAs($customer)->getJson(route('customer.messages.show', [$studio, 'after' => $ids[69]]))->assertOk();
        $last->assertJsonPath('state.has_more_after', false)->assertJsonCount(50, 'messages');

        $this->assertFalse(app(ThreadReader::class)->page($studio)['has_more_after']);
    }

    // 0g: the unread endpoint

    public function test_the_unread_endpoint_counts_and_never_creates_the_studio_thread(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->getJson(route('customer.messages.unread'))->assertOk()->assertExactJson(['studio_unread' => 0]);
        $this->assertSame(0, Conversation::query()->count());

        $studio = $this->studio($customer);
        $this->say($studio, $customer);
        $this->ownerSays($studio, $this->owner(), 'One');
        $this->ownerSays($studio, $this->owner(), 'Two');

        $this->actingAs($customer)->getJson(route('customer.messages.unread'))->assertExactJson(['studio_unread' => 2]);
        $this->actingAs($customer)->postJson(route('customer.messages.read', $studio))->assertOk();
        $this->actingAs($customer)->getJson(route('customer.messages.unread'))->assertExactJson(['studio_unread' => 0]);

        $this->actingAs($this->owner())->getJson(route('customer.messages.unread'))->assertForbidden();
    }
}
