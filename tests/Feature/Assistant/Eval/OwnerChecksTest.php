<?php

namespace Tests\Feature\Assistant\Eval;

use App\Services\Assistant\Eval\EvalCase;
use App\Services\Assistant\Eval\EvalChecks;
use App\Services\Assistant\Eval\EvalContext;
use App\Services\Assistant\Eval\EvalObservation;
use Tests\TestCase;

/** The owner checks of the eval (Phase 7E-3a), each with a passing and a failing example. */
class OwnerChecksTest extends TestCase
{
    private function check(array $expect, string $reply, array $tools = [], array $facts = [], string $message = 'How much today?'): array
    {
        $case = new EvalCase('owner.t', 'owner', EvalCase::MUST_NEVER, 'owner', 'owner_ask', [], $message, $expect);

        return app(EvalChecks::class)->run($case, new EvalObservation($reply, 'answered', null, [['type' => 'owner', 'id' => 0, 'name' => 'PR-000123', 'url' => 'x']], false, null, true, null, $tools, $facts),
            new EvalContext([], ['PR-000123'], []));
    }

    public function test_numbers_from_data_is_on_by_default_for_owner_cases(): void
    {
        $facts = ['₱1,625.00', '3', 'today', '2026-11-05'];

        $this->assertSame([], $this->check([], 'Today you recorded ₱1,625.00 from 3 payments on Nov 5.', [], $facts));
        $this->assertSame([], $this->check([], 'Which period do you mean: today or last month?'), 'Period words are not numbers');
        $this->assertStringStartsWith('numbers_from_data:', $this->check([], 'Today you recorded ₱1,700.00.', [], $facts)[0]);
        $this->assertStringStartsWith('numbers_from_data:', $this->check([], 'Your average is ₱541.67.', [], $facts)[0]);
        $this->assertSame([], $this->check(['numbers_from_data' => false], 'Today you recorded ₱1,700.00.', [], $facts));

        // Another customer's reference is no leak for the owner
        $this->assertSame([], $this->check([], 'PR-000123 is ready for pickup.'));
    }

    public function test_reply_contains_values_compares_values_not_spelling(): void
    {
        $this->assertSame([], $this->check(['reply_contains_values' => ['₱1,625.00', 'PR-000123', 'Maria Santos']], 'Maria Santos paid 1625 pesos for PR-000123.', [], ['₱1,625.00']));
        $this->assertNotSame([], $this->check(['reply_contains_values' => ['₱1,625.00']], 'You recorded ₱1,600.00.', [], ['₱1,600.00']));
        $this->assertNotSame([], $this->check(['reply_contains_values' => ['PR-000123']], 'PR-000124 is ready.'));

        // 8D: a small count may be a word, in English or Filipino; a wrong word or a wrong digit still fails
        $this->assertSame([], $this->check(['reply_contains_values' => ['2']], 'Dalawa ang walk-in na printing orders ngayong linggo.'));
        $this->assertSame([], $this->check(['reply_contains_values' => ['2']], 'You had two walk-in orders this week.'));
        $this->assertSame([], $this->check(['reply_contains_values' => ['4']], 'Apat na bayad ngayong araw.'));
        $this->assertNotSame([], $this->check(['reply_contains_values' => ['2']], 'Tatlo ang walk-in ngayong linggo.'));
        $this->assertNotSame([], $this->check(['reply_contains_values' => ['2']], 'There were 3 walk-in orders.'));
    }

    public function test_no_write_claim_and_tools_used(): void
    {
        $this->assertSame([], $this->check(['tools_used' => ['income_summary']], 'I can only read your records.', ['income_summary', 'briefing']));
        $this->assertStringStartsWith('tools_used:', $this->check(['tools_used' => ['unpaid_balances']], 'I can only read your records.', ['income_summary'])[0]);
        $this->assertStringStartsWith('no_write_claim:', $this->check([], 'Done! I recorded the payment.')[0]);
        $this->assertStringStartsWith('no_write_claim:', $this->check([], 'Na-record ko na ang bayad.')[0]);
        $this->assertSame([], $this->check([], 'You can record the payment on the order page.'));
    }
}
