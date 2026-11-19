<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Enums\MessageSender;
use App\Enums\OwnerPresenceMode;
use App\Enums\SystemMessageKind;
use App\Models\AssistantRun;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use App\Services\Assistant\LumiStatus;
use App\Services\Chat\LumiReplyService;
use App\Services\Chat\MessageService;
use App\Services\ShopSettingsService;
use App\Support\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Chat\ChatHelpers;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** Phase 10E: Settings > Lumi assistant (switch, status, test, fallback text) and the away replies when Lumi is off or down. */
class LumiSettingsTest extends TestCase
{
    use ChatHelpers, FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAssistant();
    }

    private function switchLumi(bool $on, ?string $text = null): void
    {
        app(ShopSettingsService::class)->saveLumi($on, $text);
        app(ShopSettings::class)->forget();
    }

    public function test_the_owner_switches_lumi_off_and_customers_get_the_fallback_without_a_provider_call(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('owner.settings.lumi'), ['enabled' => '0', 'fallback_text' => 'Lumi is resting. Message the studio and we will reply soon.'])
            ->assertRedirect(route('owner.settings.index').'#lumi');
        app(ShopSettings::class)->forget();

        $result = $this->ask('What packages do you have?');
        $this->assertSame(AssistantOutcome::FallbackDisabled, $result->outcome);
        $this->assertSame('Lumi is resting. Message the studio and we will reply soon.', $result->reply);
        $this->assertNull($this->fake->lastRequest());

        // The owner's own Ask Lumi says so plainly
        $this->actingAs($owner)->postJson(route('owner.assistant.ask'), ['message' => 'How much did we make today?'])->assertOk()->assertJsonPath('reply', 'Lumi is turned off in Settings.');

        // Both switches must allow it: the owner's ON does not override the server's OFF
        $this->switchLumi(true);
        config(['assistant.enabled' => false]);
        $this->assertSame(AssistantOutcome::FallbackDisabled, $this->ask('Hello?')->outcome);
    }

    public function test_the_fallback_text_is_plain_limited_and_can_be_reset(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('owner.settings.lumi'), ['enabled' => '1', 'fallback_text' => str_repeat('a', 301)])->assertSessionHasErrors('fallback_text');
        $this->actingAs($owner)->post(route('owner.settings.lumi'), ['enabled' => '1', 'fallback_text' => "Two\nlines"])->assertSessionHasErrors('fallback_text');

        $this->actingAs($owner)->post(route('owner.settings.lumi'), ['enabled' => '1', 'fallback_text' => 'Back soon.']);
        $this->assertSame('Back soon.', DB::table('shop_settings')->value('lumi_fallback_text'));
        $this->actingAs($owner)->post(route('owner.settings.lumi'), ['enabled' => '1', 'fallback_text' => 'Back soon.', 'reset_fallback' => '1']);
        $this->assertNull(DB::table('shop_settings')->value('lumi_fallback_text'));

        $this->fake->push(new ProviderUnavailable('down'));
        app(ShopSettings::class)->forget();
        $this->assertSame(config('assistant.messages.unavailable'), $this->ask('Which packages?')->reply);
    }

    public function test_the_status_panel_counts_from_run_metadata_only(): void
    {
        $owner = $this->owner();
        $this->fake->push(self::reply('answer', 'We have portrait and family sessions.'), self::reply('handoff', 'Let me pass you to the studio.'), new ProviderUnavailable('down'));
        $this->ask('Packages?', AssistantFeature::AskLumi, AssistantActor::user($owner->id));
        $this->ask('Can I change my booking?', AssistantFeature::AskLumi, AssistantActor::user($owner->id));
        $this->ask('Again?', AssistantFeature::AskLumi, AssistantActor::user($owner->id));

        $status = app(LumiStatus::class)->summary();

        $this->assertSame(2, $status['answers_today']);
        $this->assertSame(1, $status['handoffs_today']);
        $this->assertSame(1, $status['fallbacks_today']);
        $this->assertSame(1, $status['errors_last_hour']);
        // Phase 10H: plain words, no provider names (was "Fake (test provider)")
        $this->assertSame('the test assistant', $status['last_answer_by']);
        $this->assertSame(LumiStatus::WORKING, $status['state']);

        $page = $this->actingAs($owner)->get(route('owner.settings.index'))->assertOk();
        $page->assertSee('Lumi assistant')->assertSee('data-lumi-state="working"', false)->assertSee('Answers today')->assertDontSee('api_key');

        // Every recent call failing: "Down"
        AssistantRun::query()->delete();
        $this->fake->push(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
        $this->ask('One?');
        $this->ask('Two?');
        $this->assertSame(LumiStatus::DOWN, app(LumiStatus::class)->summary()['state']);

        $this->switchLumi(false);
        $this->assertSame(LumiStatus::OFF, app(LumiStatus::class)->summary()['state']);
    }

    public function test_the_test_button_names_the_provider_and_is_throttled(): void
    {
        $owner = $this->owner();
        $this->fake->push(self::reply('answer', 'Portraits, couples and families.'), new ProviderUnavailable('down'));

        $this->actingAs($owner)->post(route('owner.settings.lumi.test'))->assertRedirect(route('owner.settings.index').'#lumi')
            ->assertSessionHas('status', fn (string $text) => str_starts_with($text, 'Lumi answered in ') && str_ends_with($text, 'by the test assistant.'));
        $this->assertSame(1, AssistantRun::query()->where('user_id', $owner->id)->count());

        // Phase 10H: plain words, not the raw outcome code (was "fallback unavailable"); fixed in 10I
        $this->actingAs($owner)->post(route('owner.settings.lumi.test'))->assertSessionHas('error', fn (string $text) => str_contains($text, 'the AI service did not respond') && ! str_contains($text, 'fallback_'));
        $this->actingAs($owner)->post(route('owner.settings.lumi.test'));
        $this->actingAs($owner)->post(route('owner.settings.lumi.test'))->assertStatus(429);

        $this->actingAs($this->customer())->post(route('owner.settings.lumi.test'))->assertForbidden();
    }

    public function test_switched_off_while_the_owner_is_away_the_studio_thread_says_it_once(): void
    {
        DB::table('shop_settings')->updateOrInsert(['id' => 1], ['opening_hours' => 'Mon-Sat 9:00 AM - 5:00 PM', 'created_at' => now(), 'updated_at' => now()]);
        $this->switchLumi(false, 'Lumi is resting.');
        $this->presence(OwnerPresenceMode::Away);
        $customer = $this->customer();
        $studio = $this->studio($customer);

        $this->say($studio, $customer, 'Hello?');
        $first = app(LumiReplyService::class)->reply($studio, $customer);
        $this->say($studio, $customer, 'Anyone there?');
        $second = app(LumiReplyService::class)->reply($studio->fresh(), $customer);

        $notices = $studio->messages()->where('sender_type', MessageSender::System)->get();
        $this->assertCount(1, $notices);
        // Switched OFF: the line gives the shop's hours as written, without the "Lumi is down" text
        $this->assertSame('The studio is away right now and will reply when back. Shop hours: Mon-Sat 9:00 AM - 5:00 PM.', $notices[0]->body);
        $this->assertSame(SystemMessageKind::AiUnavailable, $notices[0]->systemKind());
        $this->assertSame(0, $studio->messages()->where('sender_type', MessageSender::Assistant)->count());
        $this->assertSame(2, $studio->messages()->where('sender_type', MessageSender::Customer)->count());
        $this->assertNotSame('replied', $second->status);
        $this->assertSame('replied', $first->status);
    }

    public function test_down_while_the_owner_is_away_adds_the_owners_text_and_never_an_error_bubble(): void
    {
        DB::table('shop_settings')->updateOrInsert(['id' => 1], ['opening_hours' => 'Mon-Sat 9:00 AM - 5:00 PM', 'lumi_fallback_text' => 'Lumi is resting.', 'created_at' => now(), 'updated_at' => now()]);
        app(ShopSettings::class)->forget();
        $this->presence(OwnerPresenceMode::Away);
        $this->fake->push(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
        $customer = $this->customer();
        $studio = $this->studio($customer);

        $this->say($studio, $customer, 'Is my order ready?');
        app(LumiReplyService::class)->reply($studio, $customer);
        $this->say($studio, $customer, 'Hello?');
        app(LumiReplyService::class)->reply($studio->fresh(), $customer);

        $notices = $studio->messages()->where('sender_type', MessageSender::System)->pluck('body')->all();
        $this->assertSame(['The studio is away right now and will reply when back. Shop hours: Mon-Sat 9:00 AM - 5:00 PM. Lumi is resting.'], $notices);
        $this->assertSame(0, $studio->messages()->where('sender_type', MessageSender::Assistant)->count());
        // The owner still has both messages, unread
        $this->assertSame(2, app(MessageService::class)->unreadCount($studio->fresh(), $this->owner()));
    }

    public function test_the_five_reply_cap_still_holds(): void
    {
        $this->presence(OwnerPresenceMode::Away);
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $cap = (int) config('assistant.limits.studio_away_max_consecutive');

        foreach (range(1, $cap + 1) as $i) {
            $this->fake->push(self::reply('answer', "Answer {$i}."));
            $this->say($studio, $customer, "Question {$i}?");
            app(LumiReplyService::class)->reply($studio->fresh(), $customer);
        }

        $this->assertSame($cap, $studio->messages()->where('sender_type', MessageSender::Assistant)->count());
    }
}
