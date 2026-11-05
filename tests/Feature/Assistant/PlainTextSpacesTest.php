<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\ResponseValidator;
use App\Support\PlainText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * The missing-space display bug (Phase 7E-2c): a model sometimes writes a special or zero-width space, which some fonts
 * draw with no gap ("Nov3", "don'taccept"). Every model reply now goes through PlainText::spaces().
 */
class PlainTextSpacesTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    private const SPACES = ['U+202F' => "\u{202F}", 'U+2009' => "\u{2009}", 'U+00A0' => "\u{00A0}", 'U+200A' => "\u{200A}", 'U+2007' => "\u{2007}", 'tab' => "\t"];

    private const INVISIBLE = ['U+200B' => "\u{200B}", 'U+200C' => "\u{200C}", 'U+200D' => "\u{200D}", 'U+2060' => "\u{2060}", 'U+FEFF' => "\u{FEFF}"];

    public function test_every_special_space_becomes_one_normal_space_in_every_position(): void
    {
        foreach (self::SPACES as $name => $c) {
            $this->assertSame("We don't accept online payments.", PlainText::spaces("We don't{$c}accept online payments."), $name);
            $this->assertSame('Tue, Nov 3, 2026', PlainText::spaces("Tue,{$c}Nov{$c}3,{$c}2026"), $name);
            $this->assertSame('It is ₱1,200.00 only.', PlainText::spaces("It is ₱1,200.00{$c}only."), $name);
            $this->assertSame('a b', PlainText::spaces("a {$c} b"), "{$name} next to a normal space collapses");
            $this->assertSame(' lead', PlainText::spaces("{$c}lead"), "{$name} at the start");
        }
    }

    public function test_zero_width_characters_between_two_words_become_a_space(): void
    {
        foreach (self::INVISIBLE as $name => $c) {
            $this->assertSame("We don't accept online payments.", PlainText::spaces("We don't{$c}accept online payments."), $name);
            $this->assertSame('Tue, Nov 3, 2026', PlainText::spaces("Tue, Nov{$c}3, 2026"), $name);
            $this->assertSame('₱1,200.00 only', PlainText::spaces("₱1,200.00{$c}only"), $name);
        }
    }

    public function test_zero_width_characters_inside_a_word_or_number_or_next_to_a_space_are_removed(): void
    {
        foreach (self::INVISIBLE as $name => $c) {
            $this->assertSame("don't", PlainText::spaces("don{$c}'t"), "{$name} before an apostrophe");
            $this->assertSame("don't", PlainText::spaces("don'{$c}t"), "{$name} after an apostrophe");
            $this->assertSame('e-mail', PlainText::spaces("e{$c}-mail"), "{$name} before a hyphen");
            $this->assertSame('₱1,200.00', PlainText::spaces("₱1,{$c}200.00"), "{$name} inside a number");
            $this->assertSame('₱1200', PlainText::spaces("₱1{$c}200"), "{$name} between digits");
            $this->assertSame('Nov 3', PlainText::spaces("Nov {$c}3"), "{$name} next to a space");
            $this->assertSame('end.', PlainText::spaces("end.{$c}"), "{$name} at the end");
            $this->assertSame('Nov 3', PlainText::spaces("Nov{$c}{$c}{$c}3"), "a run of {$name} is one gap");
        }
    }

    public function test_emoji_filipino_and_punctuation_stay_as_they_are(): void
    {
        foreach ([
            'Salamat po! 📸✨',
            'Ñoño, mañana, kumusta ka?',
            "Bukas po kami ngayon hanggang 5:00 PM. Pwede po ang GCash (09XX).\nIngat!",
            "The family emoji \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} stays one emoji.",
            "Thumbs up \u{1F44D}\u{1F3FD} and a heart \u{2764}\u{FE0F}.",
            'Email: shop@example.test; price: ₱3,500.00 — final after review.',
        ] as $text) {
            $this->assertSame($text, PlainText::spaces($text), $text);
        }
    }

    public function test_the_validator_uses_it_and_its_other_rules_are_unchanged(): void
    {
        $validator = app(ResponseValidator::class);

        $this->assertSame("We don't accept online payments. Your shoot is on Tue, Nov 3, 2026.",
            $validator->plainText("We don't\u{202F}accept online payments. Your shoot is on Tue, Nov\u{200B}3, 2026."));
        $this->assertSame('Bold and a link', $validator->plainText('**Bold** and [a link](https://example.com)'));
        $this->assertSame("Line one\n\nLine two", $validator->plainText("Line one\n\n\n\nLine two"));
    }

    public function test_every_feature_gets_clean_text_including_the_owner_assist(): void
    {
        $this->fakeAssistant();
        $this->fake->push(self::reply('answer', "We don't\u{2009}accept online payments, sorry."));
        $this->assertSame("We don't accept online payments, sorry.", $this->ask('Online payment?')->reply);

        $owner = User::factory()->owner()->create();
        $this->fake->push(self::reply('answer', "Hi! Your shoot is on Nov\u{202F}9 at 2:00\u{00A0}PM."));
        $this->assertSame('Hi! Your shoot is on Nov 9 at 2:00 PM.', $this->ask('When?', AssistantFeature::OwnerAssistSuggest, AssistantActor::user($owner->id))->reply);

        $this->fake->push(self::reply('answer', "- Asks\u{200B}about canvas\n- Nothing\u{FEFF}answered yet"));
        $this->assertSame("- Asks about canvas\n- Nothing answered yet", $this->ask('Summary', AssistantFeature::OwnerAssistSummarize, AssistantActor::user($owner->id))->reply);
    }
}
