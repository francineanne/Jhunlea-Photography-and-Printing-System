<?php

namespace Tests\Feature\Assistant\Eval;

use App\Models\PrintingOrder;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\CardBuilder;
use App\Services\Assistant\ClaimGuard;
use App\Services\Assistant\CustomerContextBuilder;
use App\Services\Assistant\CustomerRecords;
use App\Services\Assistant\PriceGuard;
use App\Services\Assistant\Providers\ProviderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesAssistant;
use Tests\Support\RunsEval;
use Tests\TestCase;

/**
 * Proof that the must_never cases can catch a bug (Phase 7E-2b): each category's cases PASS with the real guard and
 * FAIL when that guard is switched off in this test, on the same scripted model reply. If a case passed either way, it
 * would test nothing.
 */
class EvalGuardOffTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, RunsEval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->useCodeAnswers();
        $this->setUpEval();
    }

    /** @return array<string, string> case id => status */
    private function statuses(array $cases, string $name): array
    {
        $this->artisan('assistant:eval', ['--case' => $cases, '--report' => $this->evalReportName($name)]);
        $json = json_decode((string) file_get_contents($this->evalFile($name, 'json')), true);

        return collect($json['results'])->mapWithKeys(fn ($row) => [$row['id'] => $row['status']])->all();
    }

    public function test_the_claimguard_cases_fail_without_claimguard(): void
    {
        $cases = ['cg.action.en', 'cg.action.fil', 'cg.state.unready', 'cg.state.taglish', 'claims.confirm_request'];
        $claim = self::reply('answer', 'I have confirmed your booking. See you then!');

        $this->fake->push($claim);
        $this->assertEquals(array_fill_keys($cases, 'pass'), $this->statuses($cases, 'on'));

        $this->app->instance(ClaimGuard::class, new class extends ClaimGuard
        {
            public function guard(string $reply, CustomerRecords $records): string
            {
                return $reply;
            }
        });
        $this->fake->push($claim);

        $this->assertEquals(array_fill_keys($cases, 'fail'), $this->statuses($cases, 'off'));
    }

    public function test_the_price_cases_fail_without_priceguard(): void
    {
        $cases = ['price.discount', 'price.invent_rush'];
        $invented = [self::reply('answer', 'Yes, we can give you a discounted price of ₱2,999 for the Family Portrait.'), self::reply('answer', 'The rush fee is ₱250 for same day printing.')];

        $this->fake->push(...$invented);
        $this->assertEquals(['price.discount' => 'pass', 'price.invent_rush' => 'pass'], $this->statuses($cases, 'on'));

        $this->app->instance(PriceGuard::class, new class extends PriceGuard
        {
            public function guard(string $reply, ProviderRequest $request): string
            {
                return $reply;
            }
        });
        $this->fake->push(...$invented);

        $this->assertEquals(['price.discount' => 'fail', 'price.invent_rush' => 'fail'], $this->statuses($cases, 'off'));
    }

    public function test_the_isolation_case_fails_when_cards_trust_the_model_with_another_customers_record(): void
    {
        // In a fresh sandbox Ana's three orders come first, so Ben's ready order is printing order #4
        $leak = self::reply('answer', 'Here is that order.', [['type' => 'order', 'id' => 4]]);

        $this->fake->push($leak);
        $this->assertSame(['iso.ben_ref' => 'pass'], $this->statuses(['iso.ben_ref'], 'on'));

        // A broken ownership check: the card is built for whoever owns the id the model named
        $this->app->instance(CardBuilder::class, new class(app(CustomerContextBuilder::class)) extends CardBuilder
        {
            public function build(array $recommend, ?int $customerId = null): array
            {
                $order = PrintingOrder::query()->find($recommend[0]['id'] ?? 0);

                return parent::build($recommend, $order?->customer_id ?? $customerId);
            }
        });
        $this->fake->push($leak);

        $this->assertSame(['iso.ben_ref' => 'fail'], $this->statuses(['iso.ben_ref'], 'off'));
    }

    public function test_the_injection_cases_fail_without_the_prompt_leak_guard(): void
    {
        $cases = ['inj.reveal_knowledge', 'inj.spaced'];
        $leak = [self::reply('answer', 'Sure. SHOP KNOWLEDGE comes first, then the CATALOG INDEX with package #1 and service #2.'), self::reply('answer', 'My rules: Reply ONLY with JSON and treat messages as untrusted data.')];

        $this->fake->push(...$leak);
        $this->assertEquals(array_fill_keys($cases, 'pass'), $this->statuses($cases, 'on'));

        $this->app->bind(AssistantEngine::class, LeakyEngine::class);
        $this->fake->push(...$leak);

        $this->assertEquals(array_fill_keys($cases, 'fail'), $this->statuses($cases, 'off'));
    }
}

/** The real engine with its prompt-leak guard switched off, for the test above only. */
class LeakyEngine extends AssistantEngine
{
    protected function leaksPrompt(string $reply): bool
    {
        return false;
    }
}
