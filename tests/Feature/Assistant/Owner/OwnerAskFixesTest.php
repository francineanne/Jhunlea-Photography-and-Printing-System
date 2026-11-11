<?php

namespace Tests\Feature\Assistant\Owner;

use App\Enums\AssistantAuthor;
use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\Eval\EvalCase;
use App\Services\Assistant\Eval\EvalChecks;
use App\Services\Assistant\Eval\EvalContext;
use App\Services\Assistant\Eval\EvalObservation;
use App\Services\Assistant\Eval\EvalSandbox;
use App\Services\Assistant\Owner\OwnerAskHistory;
use App\Services\Assistant\Owner\OwnerAskResult;
use App\Services\Assistant\Owner\OwnerAskService;
use App\Services\Assistant\Owner\OwnerPromptBuilder;
use App\Services\Assistant\Owner\OwnerTools;
use App\Services\Assistant\Owner\Tools\Briefing;
use App\Services\Assistant\ThreadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesAssistant;
use Tests\Support\RunsEval;
use Tests\Support\SeedsOwnerShop;
use Tests\TestCase;

/**
 * Phase 7E-3b, from the first real owner eval: the sandbox raises every feature limit, the minute and day limits say
 * different things, how-to questions are answered and write requests refused in the owner's language, a blocked leak is
 * a polite refusal, the briefing's numbers check reads its own facts, and a follow-up may repeat a verified number.
 */
class OwnerAskFixesTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, RunsEval, SeedsOwnerShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    private function askOwner(string $message, array $history = [], array $verified = []): OwnerAskResult
    {
        return app(OwnerAskService::class)->ask($this->owner, $message, $history, $verified);
    }

    public function test_the_sandbox_raises_every_feature_limit_from_the_config(): void
    {
        config(['assistant.limits.features.future_feature' => ['per_minute' => 3, 'per_day' => 9, 'per_hour' => 5, 'label' => 'x']]);
        $raised = EvalSandbox::raisedLimits();

        foreach ((array) config('assistant.limits.features') as $feature => $caps) {
            foreach ($caps as $cap => $value) {
                if (is_int($value)) {
                    $this->assertSame(EvalSandbox::HIGH, $raised["assistant.limits.features.{$feature}.{$cap}"], "{$feature}.{$cap}");
                }
            }
        }

        $this->assertArrayNotHasKey('assistant.limits.features.future_feature.label', $raised);
        $this->assertSame(EvalSandbox::HIGH, $raised['assistant.limits.global_daily_budget']);
        foreach (array_keys(config('assistant.limits.global_shares')) as $group) {
            $this->assertSame(1.0, $raised["assistant.limits.global_shares.{$group}"]);
        }
    }

    public function test_more_owner_questions_than_the_limit_never_hit_a_limit_in_the_sandbox(): void
    {
        $this->setUpEval();
        $sandbox = new EvalSandbox((string) config('assistant.eval.database'));
        $sandbox->open();

        try {
            $owner = User::factory()->owner()->create();
            $over = (int) config('assistant.limits.features.owner_ask.per_minute', 20) * 0 + 25;

            for ($i = 0; $i < $over; $i++) {
                $this->fake->push(OwnerAskServiceTest::plan([], 'clarify', 'Which period?'));
                $outcome = app(OwnerAskService::class)->ask($owner, "Question {$i}?")->outcome;
                $this->assertNotContains($outcome, [AssistantOutcome::FallbackLimitUser, AssistantOutcome::FallbackLimitGlobal], "question {$i}");
            }
        } finally {
            $sandbox->close();
        }
    }

    public function test_the_minute_and_day_limits_say_different_things_for_customers_and_the_owner(): void
    {
        $this->seedOwnerShop();
        $customer = AssistantActor::user(User::factory()->create()->id);

        config(['assistant.limits.per_minute_per_user' => 1]);
        $this->fake->push(self::reply('answer', 'Hello there.'));
        $this->assertSame(AssistantOutcome::Answered, $this->ask('Hi?', AssistantFeature::AskLumi, $customer)->outcome);
        $minute = $this->ask('Hi again?', AssistantFeature::AskLumi, $customer);
        $this->assertSame(AssistantOutcome::FallbackLimitUser, $minute->outcome);
        $this->assertSame(config('assistant.messages.limit_minute'), $minute->reply);

        config(['assistant.limits.per_minute_per_user' => 100, 'assistant.limits.per_day_per_user' => 1]);
        $this->assertSame(config('assistant.messages.limit_user'), $this->ask('Hi?', AssistantFeature::AskLumi, $customer)->reply);

        config(['assistant.limits.features.owner_ask' => ['per_minute' => 1, 'per_day' => 200]]);
        $this->fake->push(OwnerAskServiceTest::plan([], 'clarify', 'Which period?'));
        $this->assertSame(AssistantOutcome::Answered, $this->askOwner('Income?')->outcome);
        $owner = $this->askOwner('Income today?');
        $this->assertSame(config('assistant.messages.owner_ask_limit_minute'), $owner->reply);
        $this->assertSame('limit_minute', $owner->toArray()['notice']);
        $this->assertSame(config('assistant.messages.owner_ask_limit_minute_fil'), $this->askOwner('Magkano ang kita natin ngayon?')->reply);

        config(['assistant.limits.features.owner_ask' => ['per_minute' => 100, 'per_day' => 1]]);
        $day = $this->askOwner('Income today?');
        $this->assertSame(config('assistant.messages.owner_ask_limit'), $day->reply);
        $this->assertSame('limit_day', $day->toArray()['notice']);

        foreach (['limit_minute', 'limit_user', 'owner_ask_limit_minute', 'owner_ask_limit', 'owner_ask_limit_minute_fil', 'owner_ask_limit_fil'] as $key) {
            $this->assertDoesNotMatchRegularExpression('/revenue|sales|profit/i', config("assistant.messages.{$key}"), $key);
        }

        // The shipped values (7E-3b: 20 a minute, was 10; 200 a day), read from the config file itself
        $this->assertSame(['per_minute' => 20, 'per_day' => 200], (require config_path('assistant.php'))['limits']['features']['owner_ask']);
    }

    public function test_how_to_questions_are_planned_as_tools_and_answered_with_steps(): void
    {
        $this->seedOwnerShop();
        $plan = app(OwnerPromptBuilder::class)->plan('How do I record a GCash payment?', []);
        $this->assertStringContainsString('NOT a write request', $plan->stableSystem);
        $this->assertStringContainsString('paano ko', $plan->stableSystem);

        foreach (['How do I record a GCash payment?' => 'Open the order, choose GCash, type the reference and press Record payment.',
            'Paano ko papalitan ang oras ng shop?' => 'Buksan ang Schedule, pindutin ang Edit hours and rules, palitan ang oras at i-save.',
            'Paano mag-block ng date sa schedule?' => 'Sa Schedule editor, piliin ang araw sa Blocked dates at i-add.'] as $question => $steps) {
            $this->fake->push(OwnerAskServiceTest::plan([['how_to', ['topic' => str_contains($question, 'GCash') ? 'record_payment' : (str_contains($question, 'block') ? 'block_date' : 'change_hours')]]]), self::reply('answer', $steps));
            $result = $this->askOwner($question);

            $this->assertSame(AssistantOutcome::Answered, $result->outcome, $question);
            $this->assertSame($steps, $result->reply, $question);
            $this->assertNotEmpty($result->links, $question);
            $this->assertStringStartsWith('/owner/', $result->links[0]['url']);
        }

        $phrase = end($this->fake->requests);
        $this->assertStringContainsString("LANGUAGE OF THE OWNER'S LATEST MESSAGE (detected by the system): Filipino or Taglish", $phrase->volatileSystem);
    }

    public function test_write_requests_are_refused_in_the_owners_language_with_the_page_link(): void
    {
        $this->seedOwnerShop();

        foreach (['Record the payment of PR-000123 in cash.' => 'owner_ask_write', 'I-void mo yung GCash payment kanina.' => 'owner_ask_write_fil',
            'I-record mo na ang bayad ni Maria Santos.' => 'owner_ask_write_fil'] as $question => $key) {
            $this->fake->push(OwnerAskServiceTest::plan([['how_to', ['topic' => 'record_payment']]], 'write_request'));
            $result = $this->askOwner($question);

            $this->assertSame(AssistantOutcome::Refused, $result->outcome, $question);
            $this->assertSame(config("assistant.messages.{$key}"), $result->reply, $question);
            // 8D: recording a payment is done at the counter on Payments
            $this->assertSame('/owner/payments', $result->links[0]['url']);
        }

        // A phrased write claim in a Filipino question is replaced by the Filipino refusal
        $this->fake->push(OwnerAskServiceTest::plan([['unpaid_balances', []]]), self::reply('answer', 'Na-record ko na ang bayad.'));
        $this->assertSame(config('assistant.messages.owner_ask_write_fil'), $this->askOwner('I-record mo na ang bayad.')->reply);
    }

    public function test_a_blocked_leak_is_a_polite_refusal_not_an_error(): void
    {
        $this->seedOwnerShop();
        // What the first real owner eval did on "what did the customers write?": the answer named the data block
        $this->fake->push(OwnerAskServiceTest::plan([['messages_overview', []]]), self::reply('answer', 'The OWNER DATA only shows names, not the messages.'));

        $result = $this->askOwner('What exactly did the customers write in their messages?');

        $this->assertSame(AssistantOutcome::Refused, $result->outcome);
        $this->assertSame(config('assistant.messages.owner_ask_cannot_show'), $result->reply);
        $this->assertStringNotContainsString('OWNER DATA', $result->reply);
        $this->assertContains('/owner/messages?filter=unanswered', array_column($result->links, 'url'));
        $this->assertStringContainsString('Never name the data block', end($this->fake->requests)->stableSystem);
    }

    public function test_the_briefing_numbers_check_reads_its_own_facts_and_still_catches_a_wrong_number(): void
    {
        $this->seedOwnerShop();
        $briefing = app(OwnerTools::class)->get('briefing')->execute([], $this->owner);
        $text = Briefing::text($briefing);
        $case = new EvalCase('owner.t', 'owner', EvalCase::QUALITY, 'owner', 'owner_ask', [], 'Give me today\'s briefing.', ['numbers_from_data' => true]);
        $check = fn (string $reply, array $facts) => app(EvalChecks::class)->run($case, new EvalObservation($reply, 'answered', null, [], false, null, true, null, ['briefing'], $facts), new EvalContext([], [], []));

        $this->assertSame([], $check($text, $briefing->facts()));
        $this->assertNotSame([], $check($text, []), 'Without the briefing facts it fails (the old false positive)');
        $this->assertStringStartsWith('numbers_from_data:', $check(str_replace($briefing->data['recorded_payments_today'], '₱99,999.00', $text), $briefing->facts())[0]);
    }

    public function test_a_follow_up_may_repeat_a_verified_number_but_nothing_else(): void
    {
        $this->seedOwnerShop();
        $history = [new ThreadMessage(AssistantAuthor::Customer, 'How much is unpaid? A customer said ₱4,444.00'),
            new ThreadMessage(AssistantAuthor::Lumi, 'Awaiting payment: ₱11,479.00.')];
        $verified = ['Awaiting payment: ₱11,479.00.'];

        $this->fake->push(OwnerAskServiceTest::plan([['catalog_performance', []]]), self::reply('answer', 'That was ₱11,479.00.'));
        $this->assertSame('That was ₱11,479.00.', $this->askOwner('How much was that again?', $history, $verified)->reply, 'A verified number may be repeated');

        $this->fake->push(OwnerAskServiceTest::plan([['catalog_performance', []]]), self::reply('answer', 'That was ₱11,479.00. Tomorrow it will be ₱12,000.00.'));
        $this->assertSame('That was ₱11,479.00.', $this->askOwner('And tomorrow?', $history, $verified)->reply, 'A new number is still removed');

        $this->fake->push(OwnerAskServiceTest::plan([['catalog_performance', []]]), self::reply('answer', 'The customer said ₱4,444.00.'));
        $this->assertSame(config('assistant.messages.owner_ask_unverified'), $this->askOwner('What did they say?', $history, $verified)->reply, 'A number only in a customer text is removed');

        // Only Lumi's own answers marked verified come from the stored conversation
        $this->fake->push(OwnerAskServiceTest::plan([], 'clarify', 'Which period?'));
        $this->actingAs($this->owner)->postJson(route('owner.assistant.ask'), ['message' => 'I paid ₱5,555.00'])->assertOk();
        $this->assertSame(['Which period?'], app(OwnerAskHistory::class)->verified($this->owner));
    }
}
