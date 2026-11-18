<?php

namespace Tests\Feature\Chat;

use App\Enums\OwnerPresenceMode;
use App\Models\Conversation;
use App\Models\PrintingOrder;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\Chat\OwnerPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * Phase 10F (decision 3c): in AUTO mode every real owner action counts as activity (OwnerPresence::touch), and the
 * background polls never do. Each case starts with no activity on record and checks the one action alone.
 */
class OwnerPresenceTouchTest extends TestCase
{
    use ChatHelpers, FakesAssistant, RefreshDatabase;

    private User $owner;

    private User $ana;

    private Conversation $studio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();

        Carbon::setTestNow(Carbon::parse('2026-11-18 02:00:00', 'UTC'));
        $this->owner = $this->owner();
        $this->ana = $this->customer(['name' => 'Ana Reyes']);
        $this->studio = $this->studio($this->ana);
        $this->say($this->studio, $this->ana);
        app(OwnerPresence::class)->setMode(OwnerPresenceMode::Auto);
        $this->forget();
    }

    private function forget(): void
    {
        DB::table('shop_settings')->where('id', ShopSetting::ROW_ID)->update(['owner_last_active_at' => null]);
    }

    private function assertTouched(string $what): void
    {
        $this->assertNotNull(app(OwnerPresence::class)->lastActiveAt(), "{$what} must count as owner activity");
        $this->assertFalse(app(OwnerPresence::class)->isAway(), "{$what}: Auto reads as present");
        $this->forget();
    }

    private function assertNotTouched(string $what): void
    {
        $this->assertNull(app(OwnerPresence::class)->lastActiveAt(), "{$what} must not count as owner activity");
    }

    public function test_every_real_owner_action_touches_presence_in_auto_mode(): void
    {
        $order = PrintingOrder::factory()->forCustomer($this->ana)->create();
        $owner = $this->actingAs($this->owner);

        $owner->get(route('owner.messages.index'))->assertOk();
        $this->assertTouched('Opening the inbox page');

        $owner->getJson(route('owner.messages.index'))->assertOk();
        $this->assertTouched('Loading the inbox list');

        $owner->get(route('owner.messages.show', $this->studio))->assertOk();
        $this->assertTouched('Opening a thread page');

        $owner->getJson(route('owner.messages.show', $this->studio))->assertOk();
        $this->assertTouched('Loading a thread');

        $owner->postJson(route('owner.messages.store', $this->studio), ['body' => 'Hello Ana'])->assertCreated();
        $this->assertTouched('Sending a message');

        $owner->postJson(route('owner.messages.store', $this->studio), ['body' => 'About your order', 'about' => ['type' => 'printing_order', 'id' => $order->id]])->assertCreated();
        $this->assertTouched('Sending a message about an order');

        $owner->post(route('owner.messages.start'), ['customer' => $this->ana->id])->assertRedirect();
        $this->assertTouched('Starting a conversation');

        $owner->post(route('owner.messages.start'), ['customer' => $this->ana->id, 'about_type' => 'printing_order', 'about_id' => $order->id])->assertRedirect();
        $this->assertTouched('Starting a conversation about an order');

        $owner->postJson(route('owner.messages.resolve', $this->studio))->assertOk();
        $this->assertTouched('Resolving');

        $owner->postJson(route('owner.messages.reopen', $this->studio))->assertOk();
        $this->assertTouched('Reopening');

        $owner->postJson(route('owner.messages.presence.heartbeat'))->assertOk();
        $this->assertTouched('The heartbeat');
    }

    public function test_an_owner_started_conversation_touches_presence_for_a_brand_new_thread_too(): void
    {
        $ben = $this->customer(['name' => 'Ben Cruz']);

        $this->actingAs($this->owner)->post(route('owner.messages.start'), ['customer' => $ben->id])->assertRedirect()->assertSessionHas('chat_started', true);

        $this->assertTouched('Starting a new conversation');
    }

    public function test_background_polls_never_touch_presence(): void
    {
        $owner = $this->actingAs($this->owner);
        $last = $this->studio->messages()->max('id');

        $owner->getJson(route('owner.messages.index', ['poll' => 1]))->assertOk();
        $owner->getJson(route('owner.messages.show', [$this->studio, 'after' => $last]))->assertOk();
        $owner->getJson(route('owner.messages.presence'))->assertOk();

        $this->assertNotTouched('A background poll');
    }

    public function test_the_state_is_worded_for_the_headers(): void
    {
        $presence = app(OwnerPresence::class);

        $this->assertSame('Auto: away, no activity yet', $presence->reason());

        $presence->touch();
        Carbon::setTestNow(now()->addMinute());
        $this->assertSame('Auto: active 1 minute ago', $presence->reason());

        // 02:00 UTC = 10:00 AM Manila; away 15 minutes later
        Carbon::setTestNow(now()->addHour());
        $this->assertSame('Auto: away since 10:15 AM', $presence->reason());

        $presence->setMode(OwnerPresenceMode::Away);
        $this->assertSame('Away (set by you)', $presence->reason());
        $presence->setMode(OwnerPresenceMode::Available);
        $this->assertSame('Available (set by you)', $presence->reason());
    }

    public function test_the_thread_page_carries_the_away_prompt_and_the_presence_line(): void
    {
        $this->actingAs($this->owner)->get(route('owner.messages.show', $this->studio))->assertOk()
            ->assertSee('data-away-prompt', false)
            ->assertSee('Switch to Available')
            ->assertSee('Stay Away')
            ->assertSee('data-thread-presence', false);
    }
}
