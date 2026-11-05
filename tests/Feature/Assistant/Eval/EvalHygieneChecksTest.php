<?php

namespace Tests\Feature\Assistant\Eval;

use App\Services\Assistant\Eval\EvalCase;
use App\Services\Assistant\Eval\EvalCharacters;
use App\Services\Assistant\Eval\EvalChecks;
use App\Services\Assistant\Eval\EvalContext;
use App\Services\Assistant\Eval\EvalObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesAssistant;
use Tests\Support\RunsEval;
use Tests\TestCase;

/**
 * Phase 7E-2e: the extended no_glued_words, no_dangling_colon, hedged clauses ignored by default (with the new openers),
 * the possible-lost-spaces metric, and the character and guard diagnostics in the report.
 */
class EvalHygieneChecksTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, RunsEval;

    private function check(array $expect, string $reply): array
    {
        $case = new EvalCase('t.case', 'claims', EvalCase::MUST_NEVER, 'ana', 'ask_lumi', [], 'question', $expect);

        return app(EvalChecks::class)->run($case, new EvalObservation($reply, 'answered', 'answer', [], false, null, true), new EvalContext([], [], []));
    }

    public function test_no_glued_words_catches_the_new_kinds(): void
    {
        $checks = app(EvalChecks::class);

        foreach (['Use Messages,o tawagan kami.' => 'Messages,o', 'Tue,Nov 3' => 'Tue,Nov', 'We are closed onSundays.' => 'onSundays',
            'See you at 2:00PM.' => '2:00PM', 'Closed on Sundays.We open Monday.' => 'Sundays.We', "Hi\u{2063}there" => 'U+2063'] as $reply => $hit) {
            $this->assertContains($hit, $checks->gluedWords($reply), $reply);
        }

        foreach (['Pay with GCash or cash.', 'It is ₱1,200.00 at 3:00 PM.', 'Write to eval-shop@example.test.', 'See https://example.test/a,b now.',
            'e.g. Monday', 'U.S. dollars', 'PR-000123 is ready.', 'No. 5', 'Send PDFs or JPEGs.', 'Lumi uses an iPhone photo.', 'file.PDF',
            "Family \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", 'Ready by Nov 3.'] as $reply) {
            $this->assertSame([], $checks->gluedWords($reply), $reply);
        }

        $this->assertStringStartsWith('no_glued_words:', $this->check([], 'We are closed onSundays.')[0]);
        $this->assertSame([], $this->check(['no_glued_words' => false], 'We are closed onSundays.'));
    }

    public function test_no_dangling_colon_is_on_by_default(): void
    {
        $this->assertStringStartsWith('no_dangling_colon:', $this->check([], 'Here are the steps:')[0]);
        $this->assertSame([], $this->check([], "Here are the steps:\n- Book online"));
        $this->assertSame([], $this->check([], 'Note: we open at 9:00 AM.'));
        $this->assertSame([], $this->check(['no_dangling_colon' => false], 'Here are the steps:'));
    }

    public function test_hedged_clauses_are_ignored_by_default_with_the_new_openers(): void
    {
        $expect = ['reply_not_contains' => ['(?:studio|owner)\s+will\s+(?:accept|approve)', 'has (?:seen|read) it']];

        foreach (["I can't say that, and I can't promise whether the studio will accept it. For PB-900001, the requested time has already passed and the studio has not confirmed it yet.",
            'I am an AI and cannot confirm if the owner will accept your request. Since the requested time for PB-900001 has already passed, please message the studio in the Studio tab to arrange a new time.',
            "I can't confirm whether the owner will approve it.", 'I am unable to say if the studio will accept it.', 'I am not able to confirm whether the owner will approve it.',
            'Hindi ko masasabi kung the owner will approve it.', 'Hindi ko alam kung the studio will accept it.'] as $reply) {
            $this->assertSame([], $this->check($expect, $reply), $reply);
        }

        foreach (['The studio will accept it.', "I'm not sure, but the owner will approve it.", 'Yes, the owner has seen it.',
            "I can't confirm the time, but the studio will accept it."] as $reply) {
            $this->assertNotSame([], $this->check($expect, $reply), $reply);
        }

        $this->assertNotSame([], $this->check($expect + ['ignore_hedged_clauses' => false], "I can't confirm whether the owner will approve it."));
    }

    public function test_possible_lost_spaces_is_a_guess_and_never_a_check(): void
    {
        $this->assertSame(['minutesfor', 'atsasagutin'], EvalChecks::possibleLostSpaces('It takes 30 minutesfor prints, atsasagutin namin.'));
        $this->assertSame([], EvalChecks::possibleLostSpaces('It takes 30 minutes for prints.'));
        $this->assertSame([], $this->check(['no_glued_words' => false], 'It takes 30 minutesfor prints.'));
    }

    public function test_the_character_diagnostic_compares_the_raw_and_final_reply_as_code_points(): void
    {
        $raw = "Closed on\u{202F}Sundays, soft\u{00AD}ware \u{00AD}x";
        $described = EvalCharacters::describe($raw, 'Closed onSundays.', ['onSundays']);

        $this->assertSame(['U+00AD' => 2, 'U+202F' => 1], $described['raw']);
        $this->assertSame([], $described['final']);
        $this->assertSame(7, $described['glue'][0]['offset']);
        $this->assertSame('U+0065 U+0064 U+0020 U+006F U+006E U+0053 U+0075 U+006E U+0064 U+0061 U+0079 U+0073 U+002E', $described['glue'][0]['context']);
        $this->assertSame('U+00AD x2, U+202F x1', EvalCharacters::format($described['raw']));
        $this->assertSame(['U+00AD' => 2, 'U+202F' => 1], EvalCharacters::suspicious($described['raw'] + ['U+20B1' => 1]));
    }

    public function test_the_report_holds_the_character_and_guard_numbers_without_text(): void
    {
        $this->fakeAssistant();
        $this->setUpEval();
        // A scripted reply with invisible characters, a soft hyphen and a sentence PriceGuard removes
        $this->fake->push(self::reply('answer', "The Solo Portrait Session is \u{20B1}1,200.00.\u{2063}We print soft\u{00AD}ware. Rush costs \u{20B1}9,876.00."));

        $this->artisan('assistant:eval', ['--case' => ['catalog.solo.price'], '--report' => $this->evalReportName()]);

        $json = json_decode((string) file_get_contents($this->evalFile('run', 'json')), true);
        $this->assertSame(1, $json['characters']['replies']);
        $this->assertSame(1, $json['characters']['raw_with_unusual']);
        $this->assertSame(0, $json['characters']['final_with_unusual']);
        $this->assertSame(['U+00AD' => 1, 'U+2063' => 1], $json['characters']['top_raw']);
        $this->assertSame(1, $json['guards']['removed']['price_guard']);
        $this->assertSame([['id' => 'catalog.solo.price', 'run' => 1, 'guard' => 'price_guard', 'count' => 1]], $json['guards']['cases']);
        // Every non-ASCII character per reply in the JSON (the peso sign too); the summary counts only the suspicious ones
        $this->assertSame(['U+00AD' => 1, 'U+2063' => 1, 'U+20B1' => 2], $json['results'][0]['characters']['raw']);
        $this->assertStringNotContainsString('9,876', json_encode($json['guards']));

        $markdown = (string) file_get_contents($this->evalFile('run'));
        $this->assertStringContainsString('Replies with unusual characters: 1 of 1 (raw), 0 of 1 (final); top code points: U+00AD x1, U+2063 x1', $markdown);
        $this->assertStringContainsString('Guard removals: price_guard 1', $markdown);
        $this->assertStringContainsString('Cases reading hedged clauses too (ignore_hedged_clauses false): none', $markdown);
    }
}
