<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Enums\MessageSender;
use App\Enums\OwnerPresenceMode;
use App\Models\AssistantRun;
use App\Models\AvailabilityRule;
use App\Models\ChatMessage;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\CodeAnswers;
use App\Services\Assistant\LimitService;
use App\Services\Chat\LumiReply;
use App\Services\Chat\LumiReplyService;
use App\Services\ShopSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Chat\ChatHelpers;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * Short, clear messages answered by code without a provider (Phase 7E-2a): a greeting, thanks, the hours and "are you
 * open now", and the contact details, in English and Filipino. Anything else, or a missing fact, goes to the model.
 */
class CodeAnswersTest extends TestCase
{
    use ChatHelpers, FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->useCodeAnswers();

        // Thursday, Nov 5, 2026, 2:00 PM in Manila
        $this->travelTo(Carbon::parse('2026-11-05 14:00', 'Asia/Manila')->utc());
    }

    private function hours(): void
    {
        foreach (range(0, 6) as $weekday) {
            $rule = new AvailabilityRule(['is_open' => $weekday !== 0, 'opens_at' => '09:00:00', 'closes_at' => '18:00:00']);
            $rule->weekday = $weekday;
            $rule->save();
        }
    }

    private function business(array $info = []): void
    {
        app(ShopSettingsService::class)->saveBusiness($info + ['shop_name' => 'Jhunlea Photography & Printing', 'address' => '12 Sample Street, Sample City', 'phone' => '0917 555 0000', 'email' => 'hello@shop.example.test']);
    }

    private function code(string $message): ?string
    {
        return app(CodeAnswers::class)->answer($message);
    }

    public function test_greetings_and_thanks_in_both_languages(): void
    {
        foreach (['hi', 'Hello!', 'hey lumi', 'Good morning', 'hello there 👋'] as $message) {
            $this->assertStringStartsWith("Hi! I'm Lumi, the AI assistant", (string) $this->code($message), $message);
        }

        foreach (['Magandang umaga po!', 'kumusta', 'Hello po', 'Musta Lumi', 'Good morning po'] as $message) {
            $this->assertStringStartsWith('Hello po! Ako si Lumi', (string) $this->code($message), $message);
        }

        foreach (['thanks!', 'Thank you so much', 'ok thanks bye', 'ty'] as $message) {
            $this->assertSame("You're welcome! Message me anytime if you have more questions.", $this->code($message), $message);
        }

        foreach (['salamat po', 'Maraming salamat!', 'sige po salamat', 'Paalam'] as $message) {
            $this->assertSame('Walang anuman po! Mag-message lang kayo ulit kung may tanong pa.', $this->code($message), $message);
        }
    }

    public function test_the_hours_and_open_now_come_from_the_schedule(): void
    {
        $this->hours();

        $english = 'The shop is open now, until 6:00 PM. Our opening hours (Philippine time): Monday to Saturday 9:00 AM to 6:00 PM; Sunday closed.';

        foreach (['are you open now?', 'What are your opening hours?', 'hi! what time do you open', 'Open today?', 'store hours'] as $message) {
            $this->assertSame($english, $this->code($message), $message);
        }

        $filipino = 'Bukas po kami ngayon hanggang 6:00 PM. Ang oras namin (oras sa Pilipinas): Lunes hanggang Sabado 9:00 AM hanggang 6:00 PM; Linggo sarado.';

        foreach (['bukas ba kayo ngayon?', 'Anong oras po kayo bukas?', 'open po ba kayo?', 'Hanggang anong oras kayo?'] as $message) {
            $this->assertSame($filipino, $this->code($message), $message);
        }

        // After closing and on a day off: when it next opens
        $this->travelTo(Carbon::parse('2026-11-05 19:00', 'Asia/Manila')->utc());
        $this->assertStringStartsWith('The shop is closed now; it next opens tomorrow at 9:00 AM.', (string) $this->code('are you open now?'));
        $this->assertStringStartsWith('Sarado na po kami ngayon; magbubukas kami bukas ng 9:00 AM.', (string) $this->code('bukas pa ba kayo?'));

        $this->travelTo(Carbon::parse('2026-11-08 10:00', 'Asia/Manila')->utc());
        $this->assertStringStartsWith('The shop is closed today; it next opens tomorrow at 9:00 AM.', (string) $this->code('open today?'));
    }

    public function test_contact_details_and_the_address_come_from_the_settings(): void
    {
        $this->business();

        $this->assertSame('Here are the details of Jhunlea Photography & Printing: Address: 12 Sample Street, Sample City. You can also message the studio in the Studio tab.', $this->code('Where are you located?'));
        $this->assertSame('Here are the details of Jhunlea Photography & Printing: Phone: 0917 555 0000. You can also message the studio in the Studio tab.', $this->code('what is your phone number'));
        $this->assertSame('Here are the details of Jhunlea Photography & Printing: Phone: 0917 555 0000. Email: hello@shop.example.test. You can also message the studio in the Studio tab.', $this->code('contact details?'));
        $this->assertSame('Here are the details of Jhunlea Photography & Printing: Email: hello@shop.example.test. You can also message the studio in the Studio tab.', $this->code('your email?'));
        $this->assertSame('Ito po ang detalye ng Jhunlea Photography & Printing: Address: 12 Sample Street, Sample City. Pwede rin kayong mag-message sa studio sa Studio tab.', $this->code('saan po kayo?'));
        $this->assertSame('Ito po ang detalye ng Jhunlea Photography & Printing: Phone: 0917 555 0000. Email: hello@shop.example.test. Pwede rin kayong mag-message sa studio sa Studio tab.', $this->code('paki contact number po'));
    }

    public function test_an_hours_question_about_another_day_or_time_goes_to_the_model(): void
    {
        $this->hours();

        // Phase 7E-2b: the fixed reply only says whether the shop is open NOW
        foreach ([
            'are you open tomorrow?',
            'open ka ba tmrw',
            'Are you open on Sunday?',
            'open po ba kayo sa Linggo?',
            'bukas ba kayo sa sabado?',
            'bukas ba kayo bukas?',
            'open this weekend?',
            'are you open next week',
            'open pa ba kayo mamaya?',
            'are you open until 7pm?',
            'open ba kayo ng 5:30',
            'Bukas po ba kayo sa Lunes?',
            'what are your hours on saturday',
        ] as $message) {
            $this->assertNull($this->code($message), $message);
        }

        // Plain hours questions keep the answer by code; "bukas" once is "open"
        foreach (['are you open now?', 'what are your hours', 'bukas ba kayo ngayon?', 'anong oras kayo bukas', 'Open today?', 'store hours'] as $message) {
            $this->assertNotNull($this->code($message), $message);
        }

        // Through the engine the day question reaches the provider
        $this->assertSame(AssistantOutcome::Answered, $this->ask('are you open tomorrow?')->outcome);
        $this->assertCount(1, $this->fake->requests);
    }

    public function test_a_missing_fact_goes_to_the_model(): void
    {
        // No hours at all, no business info
        $this->assertNull($this->code('are you open now?'));
        $this->assertNull($this->code('where are you located?'));
        $this->assertNull($this->code('what is your phone number?'));
        $this->assertNull($this->code('contact details?'));

        // An address but no phone: the phone question still goes to the model
        $this->business(['phone' => null, 'email' => null]);
        $this->assertNotNull($this->code('where are you located?'));
        $this->assertNull($this->code('what is your phone number?'));
        $this->assertNull($this->code('your email?'));
    }

    public function test_anything_else_goes_to_the_model(): void
    {
        $this->hours();
        $this->business();

        foreach ([
            'hi, magkano ang family package?',
            'Hello, I want to book a photoshoot',
            'thanks, where is my order?',
            'Where is my order?',
            'are you open now and where are you located?',
            'what time can I call?',
            'how are you',
            'hi '.str_repeat('po ', 25),
            'magkano?',
            'open ba kayo para sa kasal sa Sabado?',
            'ok',
        ] as $message) {
            $this->assertNull($this->code($message), $message);
        }
    }

    public function test_through_the_engine_no_provider_is_called_and_nothing_counts(): void
    {
        $this->hours();
        Http::fake();
        $user = $this->customer();
        $actor = AssistantActor::user($user->id);

        foreach (['hi', 'are you open now?', 'salamat po'] as $message) {
            $result = $this->ask($message, actor: $actor);
            $this->assertSame(AssistantOutcome::AnsweredByCode, $result->outcome, $message);
            $this->assertSame(AssistantEngine::CODE, $result->provider);
            $this->assertFalse($result->viaBackup);
            $this->assertSame([], $result->cards);
        }

        Http::assertNothingSent();
        $this->assertSame([], $this->fake->requests, 'The provider was never asked');

        $runs = AssistantRun::query()->get();
        $this->assertCount(3, $runs);
        foreach ($runs as $run) {
            $this->assertSame('code', $run->provider);
            $this->assertSame(AssistantOutcome::AnsweredByCode, $run->outcome);
            $this->assertNull($run->model);
            $this->assertNull($run->input_tokens);
            $this->assertNull($run->output_tokens);
            $this->assertNull($run->latency_ms);
            $this->assertSame($user->id, $run->user_id);
        }

        // Not one call toward the daily limits or the shop's budget
        $this->assertSame(0, AssistantRun::query()->calledProvider()->where('created_at', '>=', app(LimitService::class)->dayStartUtc())->count());

        // A real question still reaches the model
        $this->assertSame(AssistantOutcome::Answered, $this->ask('hi, magkano ang family package?', actor: $actor)->outcome);
        $this->assertCount(1, $this->fake->requests);
    }

    public function test_answers_by_code_still_work_when_the_daily_limit_is_used_up_but_not_when_the_assistant_is_off(): void
    {
        $user = $this->customer();
        config(['assistant.limits.per_day_per_user' => 0]);

        $this->assertSame(AssistantOutcome::AnsweredByCode, $this->ask('hello', actor: AssistantActor::user($user->id))->outcome);
        $this->assertSame(AssistantOutcome::FallbackLimitUser, $this->ask('what packages do you have?', actor: AssistantActor::user($user->id))->outcome);

        config(['assistant.enabled' => false]);
        $this->assertSame(AssistantOutcome::FallbackDisabled, $this->ask('hello', actor: AssistantActor::user($user->id))->outcome);
    }

    public function test_the_owner_assist_never_gets_an_answer_by_code(): void
    {
        $owner = $this->owner();

        $this->assertSame(AssistantOutcome::Answered, $this->ask('hi', AssistantFeature::OwnerAssistSuggest, AssistantActor::user($owner->id))->outcome);
    }

    public function test_in_the_studio_thread_it_answers_only_while_the_owner_is_away_and_never_fills_the_cap(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $lumi = fn () => app(LumiReplyService::class)->reply($studio->fresh(), $customer);

        // The owner is present: Lumi stays quiet, by code too
        $this->presence(OwnerPresenceMode::Available);
        $this->say($studio, $customer, 'hello po');
        $this->assertSame(LumiReply::NOT_ALLOWED, $lumi()->status);

        // Away: answered by code, stored as Lumi's words with provider "code" and no badge
        $this->presence(OwnerPresenceMode::Away);
        $reply = $lumi();
        $this->assertSame(LumiReply::REPLIED, $reply->status);
        $stored = collect($reply->messages)->last();
        $this->assertSame(MessageSender::Assistant, $stored->sender_type);
        $this->assertSame('answered_by_code', $stored->meta['outcome']);
        $this->assertSame('code', $stored->meta['provider']);
        $this->assertFalse($stored->meta['via_backup']);

        // Many answers by code in a row never reach the away cap: a real question still gets the model
        $cap = (int) config('assistant.limits.studio_away_max_consecutive');
        for ($i = 0; $i < $cap + 1; $i++) {
            $this->say($studio, $customer, 'salamat po');
            $this->assertSame(LumiReply::REPLIED, $lumi()->status);
        }

        $this->say($studio, $customer, 'How much is the family package?');
        $last = collect($lumi()->messages)->last();
        $this->assertSame('answered', $last->meta['outcome']);
        $this->assertSame(0, ChatMessage::query()->where('meta->system_kind', 'away_cap')->count());
    }

    public function test_a_guest_gets_the_code_answer_through_the_endpoint(): void
    {
        $this->hours();

        $this->postJson(route('assistant.guest'), ['message' => 'Are you open now?'])
            ->assertOk()
            ->assertJson(['outcome' => 'answered_by_code', 'intent' => 'answer', 'via_backup' => false, 'cards' => []])
            ->assertJsonPath('reply', 'The shop is open now, until 6:00 PM. Our opening hours (Philippine time): Monday to Saturday 9:00 AM to 6:00 PM; Sunday closed.');

        $this->assertSame([], $this->fake->requests);
    }
}
