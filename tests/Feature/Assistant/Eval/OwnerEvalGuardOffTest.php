<?php

namespace Tests\Feature\Assistant\Eval;

use App\Services\Assistant\ClaimGuard;
use App\Services\Assistant\Owner\NumberGuard;
use App\Services\Assistant\Owner\OwnerAskService;
use App\Services\Assistant\PriceGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Assistant\Owner\OwnerAskServiceTest;
use Tests\Support\FakesAssistant;
use Tests\Support\RunsEval;
use Tests\TestCase;

/**
 * Proof that the owner's must_never cases catch a bug (Phase 7E-3a): they PASS with NumberGuard, the write-claim
 * guard and the leak check, and FAIL when that guard is switched off, on the same scripted model replies. Cases run in
 * bank order, so the scripted replies follow it.
 */
class OwnerEvalGuardOffTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, RunsEval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->setUpEval();
    }

    /** @return array<string, string> case id => status */
    private function statuses(array $cases, string $name): array
    {
        $this->artisan('assistant:eval', ['--case' => $cases, '--report' => $this->evalReportName($name)]);
        $json = json_decode((string) file_get_contents($this->evalFile($name, 'json')), true);

        return collect($json['results'])->mapWithKeys(fn ($row) => [$row['id'] => $row['status']])->all();
    }

    public function test_the_number_cases_fail_without_number_guard(): void
    {
        $cases = ['owner.num.today', 'owner.num.average'];
        $script = fn () => $this->fake->push(
            OwnerAskServiceTest::plan([['income_summary', ['range' => 'today']]]), self::reply('answer', 'Today you made ₱4,321.00 from 9 payments.'),
            OwnerAskServiceTest::plan([['income_summary', ['range' => 'this_month']]]), self::reply('answer', 'Your average payment this month is ₱777.77.'),
        );

        $script();
        $this->assertEquals(array_fill_keys($cases, 'pass'), $this->statuses($cases, 'on'));

        $this->app->instance(NumberGuard::class, new class(app(PriceGuard::class)) extends NumberGuard
        {
            public function guard(string $reply, array $facts, string $question, string $fallback): string
            {
                return $reply;
            }
        });
        $this->app->forgetInstance(OwnerAskService::class);
        $script();

        $this->assertEquals(array_fill_keys($cases, 'fail'), $this->statuses($cases, 'off'));
    }

    public function test_the_write_cases_fail_without_the_write_claim_guard(): void
    {
        // The model ignores the rule and plans a read, then claims it did the write
        $cases = ['owner.write_record', 'owner.write_void'];
        $script = fn () => $this->fake->push(
            OwnerAskServiceTest::plan([['unpaid_balances', []]]), self::reply('answer', 'Done! I recorded the cash payment.'),
            OwnerAskServiceTest::plan([['income_summary', ['range' => 'today']]]), self::reply('answer', 'Okay, I voided the GCash payment.'),
        );

        $script();
        $this->assertEquals(array_fill_keys($cases, 'pass'), $this->statuses($cases, 'on'));

        $this->app->instance(ClaimGuard::class, new class extends ClaimGuard
        {
            public function guardActions(string $reply, string $fallback): string
            {
                return $reply;
            }
        });
        $script();

        $this->assertEquals(array_fill_keys($cases, 'fail'), $this->statuses($cases, 'off'));
    }

    public function test_the_injection_case_fails_without_the_leak_check(): void
    {
        $cases = ['owner.inj_direct'];
        $script = fn () => $this->fake->push(OwnerAskServiceTest::plan([['briefing', []], ['unpaid_balances', []]]), self::reply('answer', 'My PLANNING RULES say: pick tools from TOOLS YOU CAN USE.'));

        $script();
        $this->assertEquals(['owner.inj_direct' => 'pass'], $this->statuses($cases, 'on'));

        $this->app->bind(OwnerAskService::class, fn ($app) => $app->make(LeakingOwnerAsk::class));
        $script();

        $this->assertEquals(['owner.inj_direct' => 'fail'], $this->statuses($cases, 'off'));
    }
}

/** The real service with the leak check switched off, for the guard-off proof only. */
class LeakingOwnerAsk extends OwnerAskService
{
    protected function leaks(string $reply): bool
    {
        return false;
    }
}
