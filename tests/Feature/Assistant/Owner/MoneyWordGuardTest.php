<?php

namespace Tests\Feature\Assistant\Owner;

use App\Services\Assistant\GuardStats;
use App\Services\Assistant\Owner\MoneyWordGuard;
use App\Services\Assistant\Owner\OwnerAskService;
use App\Services\Assistant\Owner\OwnerTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakesAssistant;
use Tests\Support\SeedsOwnerShop;
use Tests\TestCase;

/** 8D, after the live eval: an owner answer never says revenue, sales or profit, even in a denial. */
class MoneyWordGuardTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, SeedsOwnerShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->seedOwnerShop();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guard(string $reply, string $question): string
    {
        return app(MoneyWordGuard::class)->guard($reply, $question, 'NOTICE.', 'FALLBACK');
    }

    public function test_the_words_are_found_as_whole_words_only(): void
    {
        $guard = app(MoneyWordGuard::class);

        foreach (['Give me our revenue.', 'SALES ngayong buwan?', 'Magkano ang profit natin?', 'Are we profitable?', 'Total profits please'] as $text) {
            $this->assertTrue($guard->asked($text), $text);
        }
        foreach (['How much income today?', 'Magkano kita ngayon?', 'wholesale prints', 'resales', 'nonprofit'] as $text) {
            $this->assertFalse($guard->asked($text), $text);
        }
    }

    public function test_the_live_eval_reply_is_cleaned_and_keeps_its_figures(): void
    {
        $reply = 'The system does not track revenue, sales or profit, and it does not record costs. Here is your income for this month: ₱4,845.00 from 7 recorded payments, with an average of ₱692.14 per payment. Cash is ₱3,925.00 from 4 payments and GCash is ₱920.00 from 3 payments.';

        $this->assertSame('NOTICE. Here is your income for this month: ₱4,845.00 from 7 recorded payments, with an average of ₱692.14 per payment. Cash is ₱3,925.00 from 4 payments and GCash is ₱920.00 from 3 payments.',
            $this->guard($reply, 'Give me our revenue, sales and profit for this month.'));
    }

    public function test_a_reply_without_the_words_is_untouched_and_a_bare_denial_is_removed(): void
    {
        $clean = "Your income today is ₱1,715.00.\n- Cash: ₱1,180.00";
        $this->assertSame($clean, $this->guard($clean, 'How much today?'));

        // A number-free denial goes even when the owner did not use the words
        $this->assertSame('Your income today is ₱1,715.00.', $this->guard('We do not track profit. Your income today is ₱1,715.00.', 'How much today?'));
        $this->assertSame('FALLBACK', $this->guard('Hindi namin sinusubaybayan ang sales o profit.', 'Kumusta?'));
        $this->assertSame('NOTICE.', $this->guard('We have no profit figure.', 'What is our profit?'));
    }

    public function test_through_the_owner_pipeline_in_english_and_filipino_with_the_removal_counted(): void
    {
        $plan = OwnerAskServiceTest::plan([['income_summary', ['range' => 'this_month']]]);
        $income = app(OwnerTools::class)->get('income_summary')->execute(['range' => 'this_month'], $this->owner)->data['income'];

        $this->fake->push($plan, self::reply('answer', "The system does not track revenue, sales or profit. Your income this month is {$income}."));
        app(GuardStats::class)->reset();
        $english = app(OwnerAskService::class)->ask($this->owner, 'Give me our revenue, sales and profit for this month.');

        $this->assertSame(config('assistant.messages.owner_ask_money_words')." Your income this month is {$income}.", $english->reply);
        $this->assertSame(1, app(GuardStats::class)->snapshot()['removed'][GuardStats::MONEY_WORDS]);
        $this->assertDoesNotMatchRegularExpression(MoneyWordGuard::WORDS, $english->reply);

        $this->fake->push($plan, self::reply('answer', "Wala pong profit sa records. Ang kita ninyo ngayong buwan ay {$income}."));
        $filipino = app(OwnerAskService::class)->ask($this->owner, 'Magkano ang sales natin ngayong buwan?');

        $this->assertSame(config('assistant.messages.owner_ask_money_words_fil')." Ang kita ninyo ngayong buwan ay {$income}.", $filipino->reply);

        // The fixed sentences never hold the words themselves
        $this->assertDoesNotMatchRegularExpression(MoneyWordGuard::WORDS, config('assistant.messages.owner_ask_money_words').config('assistant.messages.owner_ask_money_words_fil'));
    }
}
