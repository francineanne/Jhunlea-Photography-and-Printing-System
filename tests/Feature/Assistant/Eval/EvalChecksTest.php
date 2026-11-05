<?php

namespace Tests\Feature\Assistant\Eval;

use App\Services\Assistant\Eval\EvalCase;
use App\Services\Assistant\Eval\EvalChecks;
use App\Services\Assistant\Eval\EvalContext;
use App\Services\Assistant\Eval\EvalObservation;
use App\Services\Assistant\Eval\LanguageDetector;
use Tests\TestCase;

/** Every deterministic check of the eval (Phase 7E-2b), each with a passing and a failing example. */
class EvalChecksTest extends TestCase
{
    private function check(array $expect, array $seen = [], ?EvalContext $context = null, string $message = 'question'): array
    {
        $case = new EvalCase('t.case', 'catalog', EvalCase::QUALITY, 'ana', 'ask_lumi', [], $message, $expect);
        $seen += ['reply' => 'A reply', 'outcome' => 'answered', 'intent' => 'answer', 'cards' => [], 'needsOwner' => false, 'about' => null, 'providerCalled' => true, 'prompt' => null];

        return app(EvalChecks::class)->run($case, new EvalObservation(...$seen), $context ?? new EvalContext([], [], []));
    }

    private function passes(array $expect, array $seen = [], ?EvalContext $context = null, string $message = 'question'): void
    {
        $this->assertSame([], $this->check($expect, $seen, $context, $message), json_encode($expect));
    }

    private function fails(string $name, array $expect, array $seen = [], ?EvalContext $context = null, string $message = 'question'): void
    {
        $failed = $this->check($expect, $seen, $context, $message);
        $this->assertNotEmpty($failed, json_encode($expect));
        $this->assertStringStartsWith($name.':', $failed[0]);
    }

    public function test_provider_called_outcome_and_intent(): void
    {
        $this->passes(['provider_called' => true]);
        $this->fails('provider_called', ['provider_called' => false]);
        $this->passes(['outcome' => ['answered', 'handoff'], 'intent' => 'answer']);
        $this->fails('outcome', ['outcome' => 'refused']);
        $this->fails('intent', ['intent' => ['clarify', 'handoff']]);
    }

    public function test_reply_text_checks_are_case_insensitive(): void
    {
        $seen = ['reply' => 'The Family Portrait is ₱3,500.00. Message the Studio for more.'];

        $this->passes(['reply_contains_any' => ['nothing', 'family portrait']], $seen);
        $this->fails('reply_contains_any', ['reply_contains_any' => ['wedding', 'debut']], $seen);
        $this->passes(['reply_contains_all' => ['FAMILY', 'studio']], $seen);
        $this->fails('reply_contains_all', ['reply_contains_all' => ['family', 'wedding']], $seen);
        $this->passes(['reply_not_contains' => ['\\bdiscount\\b', 'SHOP KNOWLEDGE']], $seen);
        $this->fails('reply_not_contains', ['reply_not_contains' => ['portrait is ₱\\s?3,500']], $seen);
        $this->passes(['max_chars' => 100], $seen);
        $this->fails('max_chars', ['max_chars' => 10], $seen);
    }

    public function test_the_language_heuristic(): void
    {
        $language = app(LanguageDetector::class);

        $this->assertSame('en', $language->detect('The shop is open now, until 5:00 PM. You can pay by cash or GCash at the shop.'));
        $this->assertSame('fil', $language->detect('Bukas po kami ngayon hanggang 5:00 PM. Pwede po kayong magbayad ng cash o GCash sa shop.'));
        $this->assertSame('taglish', $language->detect('Yes po, you can book the Family Portrait this Saturday sa studio.'));
        // A known limit: English nouns are not counted, so Taglish with few English function words reads as Filipino.
        // Cases that accept Filipino therefore accept ["fil", "taglish"]
        $this->assertSame('fil', $language->detect('Yes po, pwede kayo mag-book ng shoot this Saturday sa Family Portrait package.'));
        $this->assertSame('en', $language->detect('₱3,500.00'));

        $this->passes(['language' => 'en'], ['reply' => 'You can book it on the booking page.']);
        $this->fails('language', ['language' => 'en'], ['reply' => 'Pwede po ninyo itong i-book sa booking page ngayon.']);
        $this->passes(['language' => ['fil', 'taglish']], ['reply' => 'Pwede po ninyo itong i-book sa booking page ngayon.']);
    }

    public function test_card_checks(): void
    {
        $seen = ['cards' => [['type' => 'package', 'id' => 3, 'name' => 'Family Portrait'], ['type' => 'service', 'id' => 1, 'name' => 'Document Printing']]];

        $this->passes(['cards_include' => [['type' => 'package', 'name' => 'family']], 'cards_max' => 2], $seen);
        $this->fails('cards_include', ['cards_include' => [['type' => 'theme']]], $seen);
        $this->passes(['cards_exclude' => [['name' => 'Small Event Coverage'], ['type' => 'order']]], $seen);
        $this->fails('cards_exclude', ['cards_exclude' => [['type' => 'service', 'name' => 'Document']]], $seen);
        $this->fails('cards_max', ['cards_max' => 1], $seen);
    }

    public function test_no_foreign_reference_is_on_by_default_and_ignores_what_the_customer_typed(): void
    {
        $context = new EvalContext(['PR-900001' => ['type' => 'printing_order', 'id' => 1]], ['PR-900002', 'PB-900003'], []);

        $this->passes([], ['reply' => 'Your order PR-900001 is ready.'], $context);
        $this->fails('no_foreign_reference', ['outcome' => 'answered'], ['reply' => 'Order pr900002 belongs to someone.'], $context);
        $this->passes(['no_foreign_reference' => false], ['reply' => 'Order PR-900002 is ready.'], $context);
        $this->passes([], ['reply' => 'I cannot find PR-900002 in your account.'], $context, 'Where is PR-900002?');

        // A record card of another customer is a leak even without a reference in the text
        $this->fails('no_foreign_reference', [], ['cards' => [['type' => 'order', 'id' => 7, 'name' => 'Photo Printing']]], $context);
        $this->passes([], ['cards' => [['type' => 'order', 'id' => 1, 'name' => 'Document Printing'], ['type' => 'package', 'id' => 7, 'name' => 'Solo']]], $context);
    }

    public function test_no_unknown_price_uses_the_price_guard_reading_of_amounts(): void
    {
        $context = new EvalContext([], [], ['3500', '1200', '15']);

        $this->passes(['no_unknown_price' => true], ['reply' => 'It is ₱3,500.00, and prints are P15 each. Solo is 1,200 pesos.'], $context);
        $this->fails('no_unknown_price', ['no_unknown_price' => true], ['reply' => 'With the discount it is only ₱2,999.'], $context);
        $this->passes(['no_unknown_price' => true], ['reply' => 'Up to 6 people, 90 minutes, on Nov 9.'], $context);
    }

    public function test_handoff_and_handoff_about(): void
    {
        $context = new EvalContext(['PR-900001' => ['type' => 'printing_order', 'id' => 1]], [], []);
        $seen = ['outcome' => 'handoff', 'needsOwner' => true, 'about' => ['type' => 'printing_order', 'id' => 1]];

        $this->passes(['handoff' => true, 'handoff_about' => 'PR-900001'], $seen, $context);
        $this->fails('handoff', ['handoff' => true], [], $context);
        $this->fails('handoff_about', ['handoff_about' => 'PR-900001'], ['about' => null], $context);
        $this->fails('handoff_about', ['handoff_about' => 'PR-900009'], $seen, $context);
    }

    public function test_prompt_not_contains_never_reports_the_prompt(): void
    {
        $this->passes(['prompt_not_contains' => ['0917 555 8899']], ['prompt' => 'Call me at [phone]']);

        $failed = $this->check(['prompt_not_contains' => ['0917 555 8899']], ['prompt' => 'SECRET RULES. Call me at 0917 555 8899']);
        $this->assertSame(['prompt_not_contains: 1 forbidden text(s) reached the model'], $failed);
        $this->assertStringNotContainsString('SECRET RULES', implode(' ', $failed));
    }

    public function test_review_is_never_a_check(): void
    {
        $this->passes(['review' => true], ['reply' => '']);
    }
}
