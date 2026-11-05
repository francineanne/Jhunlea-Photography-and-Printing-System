<?php

namespace Tests\Feature\Assistant\Owner;

use App\Services\Assistant\ClaimGuard;
use App\Services\Assistant\GuardStats;
use App\Services\Assistant\Owner\NumberGuard;
use Tests\TestCase;

/**
 * NumberGuard (Phase 7E-3a): every amount, count, percentage, date and time of an owner answer must be in the tool
 * facts, the owner's question or the small allowed set; the sentence is removed otherwise. And ClaimGuard's
 * action-claim part removes every sentence where Lumi says it did something.
 */
class NumberGuardTest extends TestCase
{
    private const FACTS = ['₱1,625.00', '3', 'today', '2026-11-05', '2:00 PM', '₱350.00', '12.5%', 'last month', '₱3,200.00', '2'];

    private function guard(string $reply, string $question = 'How much today?'): string
    {
        return app(NumberGuard::class)->guard($reply, self::FACTS, $question, 'FALLBACK');
    }

    public function test_numbers_from_the_facts_are_kept_in_every_format(): void
    {
        foreach (['Today you recorded ₱1,625.00 from 3 payments.', 'Income today: P1625.', 'That is 1,625 pesos today.', 'About 1.625k today.',
            'GCash brought ₱350.00 today.', 'Up 12.5% from last month.', 'On Nov 5 at 2:00 PM.', 'On November 5, 2026, at 2 pm.', 'On 5 Nov.',
            'Last month you recorded ₱3,200.00.', 'Ang kita ngayong araw ay ₱1,625.00.', 'Order PR-004567 is ready, see https://example.test/5/7.', '1. Open the order.'] as $reply) {
            $this->assertSame($reply, $this->guard($reply), $reply);
        }
    }

    public function test_an_invented_amount_count_or_date_removes_its_sentence(): void
    {
        $this->assertSame('Today you recorded ₱1,625.00.', $this->guard('Today you recorded ₱1,625.00. That is ₱1,700.00 more than usual.'));
        $this->assertSame('FALLBACK', $this->guard('You had 7 payments today.'), 'A wrong count');
        $this->assertSame('FALLBACK', $this->guard('Your best day was Nov 3.'), 'A wrong date');
        $this->assertSame('FALLBACK', $this->guard('The average payment is ₱541.67.'), 'A worked-out average');
        $this->assertSame('FALLBACK', $this->guard('Income grew 15%.'), 'A wrong percentage');
        $this->assertSame('FALLBACK', $this->guard('It will be 2k next week.'), 'A guess');
        $this->assertSame('FALLBACK', $this->guard('The session is at 4:00 PM.'), 'A wrong time');
        $this->assertSame('FALLBACK', $this->guard('Yesterday you recorded ₱1,625.00.'), 'A period word the data never named');
    }

    public function test_the_question_years_and_small_numbers_are_allowed(): void
    {
        $this->assertSame('From October 15 to October 28 there is no data.', $this->guard('From October 15 to October 28 there is no data.', 'Income from Oct 15 to Oct 28?'));
        $this->assertSame('In 2026 so far, 2 of them were GCash.', $this->guard('In 2026 so far, 2 of them were GCash.'));
        $this->assertSame('May 3 kang tanong, sige.', $this->guard('May 3 kang tanong, sige.'), '"May" with a small number is the Filipino word');
    }

    public function test_removals_are_counted_without_text(): void
    {
        $stats = app(GuardStats::class);
        $stats->reset();
        $this->guard("Today: ₱1,625.00.\nAlso ₱9,999.00 tomorrow.");

        $this->assertSame(['removed' => [GuardStats::NUMBER => 1], 'replaced' => []], $stats->snapshot());
    }

    public function test_write_claims_are_removed_from_owner_answers(): void
    {
        $guard = app(ClaimGuard::class);

        foreach (['I recorded the payment for PR-000123.', 'I have marked it as paid.', 'Done, I changed the price to ₱999.00.', "I've sent Maria a reply.",
            'We cancelled the booking.', 'I voided the GCash payment.', 'Na-record ko na ang bayad.', 'Binago ko na ang presyo.'] as $claim) {
            $this->assertSame('FALLBACK', $guard->guardActions($claim, 'FALLBACK'), $claim);
        }

        foreach (['You can record the payment on the order page.', 'Lumi can only read your records.', 'Should I show the unpaid orders?',
            'The payment was recorded on Nov 5.', 'Maria Santos has 2 orders.'] as $fine) {
            $this->assertSame($fine, $guard->guardActions($fine, 'FALLBACK'), $fine);
        }
    }
}
