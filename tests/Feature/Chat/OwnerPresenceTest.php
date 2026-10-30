<?php

namespace Tests\Feature\Chat;

use App\Enums\OwnerPresenceMode;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\ShopSetting;
use App\Services\Chat\OwnerPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Whether the owner counts as away (Phase 7B), and the wording of the away notice. */
class OwnerPresenceTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

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

    public function test_available_and_away_are_the_owners_choice(): void
    {
        $this->presence(OwnerPresenceMode::Available);
        $this->assertFalse($this->presenceService()->isAway());

        $this->presenceService()->touch();
        $this->presence(OwnerPresenceMode::Away);
        $this->assertTrue($this->presenceService()->isAway());
    }

    public function test_auto_follows_activity_not_the_opening_hours(): void
    {
        $this->weekHours();
        $this->assertSame(OwnerPresenceMode::Auto, $this->presenceService()->mode());
        $this->assertTrue($this->presenceService()->isAway(), 'Never active: away');

        // Sunday 11 PM in Manila, the shop is closed, but the owner is active: not away
        $this->travelTo(Carbon::parse('2026-11-15 23:00', 'Asia/Manila'));
        $this->presenceService()->touch();
        $this->assertFalse($this->presenceService()->isAway());

        $this->travel(14)->minutes();
        $this->assertFalse($this->presenceService()->isAway());

        $this->travel(2)->minutes();
        $this->assertTrue($this->presenceService()->isAway());
    }

    public function test_touch_writes_at_most_once_per_interval(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 02:00:00', 'UTC'));
        $this->presenceService()->touch();
        $first = $this->presenceService()->lastActiveAt();

        $this->travel(20)->seconds();
        $this->presenceService()->touch();
        $this->assertTrue($first->equalTo($this->presenceService()->lastActiveAt()));

        $this->travel(15)->seconds();
        $this->presenceService()->touch();
        $this->assertTrue(Carbon::parse('2026-11-10 02:00:35', 'UTC')->equalTo($this->presenceService()->lastActiveAt()));
        $this->assertSame(1, ShopSetting::query()->count());
    }

    public function test_opening_the_inbox_or_a_thread_or_replying_counts_as_activity(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->say($studio, $customer);

        $this->actingAs($owner)->getJson(route('owner.messages.index'))->assertOk();
        $this->assertFalse($this->presenceService()->isAway());

        $this->travel(16)->minutes();
        $this->assertTrue($this->presenceService()->isAway());
        $this->actingAs($owner)->getJson(route('owner.messages.show', $studio))->assertOk()->assertJsonPath('state.studio.owner_away', false);

        $this->travel(16)->minutes();
        $this->ownerSays($studio, $owner);
        $this->assertFalse($this->presenceService()->isAway());

        // The customer polling never counts as the owner being active
        $this->travel(16)->minutes();
        $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertOk()->assertJsonPath('state.studio.owner_away', true);
    }

    public function test_the_next_opening_today_tomorrow_a_later_day_or_none(): void
    {
        $this->weekHours();

        // Tuesday 7:00 AM: today
        $this->travelTo(Carbon::parse('2026-11-10 07:00', 'Asia/Manila'));
        $this->assertSame('today at 9:00 AM', $this->presenceService()->nextOpeningText());

        // Tuesday 8:00 PM: tomorrow
        $this->travelTo(Carbon::parse('2026-11-10 20:00', 'Asia/Manila'));
        $this->assertSame('tomorrow at 9:00 AM', $this->presenceService()->nextOpeningText());

        // Saturday 7:00 PM: Sunday is closed, so Monday
        $this->travelTo(Carbon::parse('2026-11-14 19:00', 'Asia/Manila'));
        $this->assertSame('Monday at 9:00 AM', $this->presenceService()->nextOpeningText());

        // Monday blocked too: Tuesday
        BlockedDate::query()->forceCreate(['date' => '2026-11-16', 'reason' => 'Holiday']);
        $this->assertSame('Tuesday at 9:00 AM', $this->presenceService()->nextOpeningText());

        DB::table('availability_rules')->update(['is_open' => false]);
        $this->assertNull($this->presenceService()->nextOpeningText());
        $this->assertSame(config('chat.messages.away_notice_no_time'), $this->presenceService()->awayNotice());
    }

    public function test_the_away_notice_uses_the_next_opening_or_says_the_ai_is_off(): void
    {
        $this->weekHours();
        $this->travelTo(Carbon::parse('2026-11-10 20:00', 'Asia/Manila'));

        $this->assertStringContainsString('will be back tomorrow at 9:00 AM', $this->presenceService()->awayNotice());

        config(['assistant.enabled' => false]);
        $this->assertSame(config('chat.messages.owner_unavailable_ai_off'), $this->presenceService()->awayNotice());
    }

    public function test_the_presence_endpoints(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->getJson(route('owner.messages.presence'))->assertOk()->assertJson(['mode' => 'auto', 'away' => true, 'last_active_at' => null]);
        $this->actingAs($owner)->postJson(route('owner.messages.presence.update'), ['mode' => 'available'])->assertOk()->assertJson(['mode' => 'available', 'away' => false]);
        $this->actingAs($owner)->postJson(route('owner.messages.presence.update'), ['mode' => 'away'])->assertOk()->assertJson(['mode' => 'away', 'away' => true]);
        $this->actingAs($owner)->postJson(route('owner.messages.presence.update'), ['mode' => 'sleeping'])->assertStatus(422)->assertJson(['code' => 'validation']);

        $this->assertSame(OwnerPresenceMode::Away, ShopSetting::query()->find(ShopSetting::ROW_ID)->owner_presence);
    }
}
