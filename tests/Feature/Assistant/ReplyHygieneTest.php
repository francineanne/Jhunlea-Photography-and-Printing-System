<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Services\Assistant\ClaimGuard;
use App\Services\Assistant\CustomerRecords;
use App\Services\Assistant\GuardStats;
use App\Services\Assistant\PriceGuard;
use App\Services\Assistant\Providers\ProviderMessage;
use App\Services\Assistant\Providers\ProviderRequest;
use App\Services\Assistant\ReplyCleanup;
use App\Services\Assistant\ResponseValidator;
use App\Support\MessageLanguage;
use App\Support\PlainText;
use Tests\TestCase;

/**
 * Phase 7E-2e: the new invisible characters, the three conservative spacing repairs (with the exact strings of the real
 * eval and the strings that must never change), the dangling colon cleanup, the GuardStats counters and the English
 * detection after Filipino words ("May I ask").
 */
class ReplyHygieneTest extends TestCase
{
    public function test_more_invisible_characters_are_handled_and_emoji_stay_intact(): void
    {
        $this->assertSame('software', PlainText::spaces("soft\u{00AD}ware"));
        $this->assertSame('a b', PlainText::spaces("a\u{2063}b"));
        $this->assertSame('a b', PlainText::spaces("a\u{034F}b"));
        $this->assertSame('a b', PlainText::spaces("a\u{061C}b"));
        $this->assertSame('a b', PlainText::spaces("a\u{180E}b"));
        $this->assertSame('a b', PlainText::spaces("a\u{E0041}b"));
        $this->assertSame('a b', PlainText::spaces("a\x07b"));
        $this->assertSame('a b', PlainText::spaces("a\u{0085}b"));
        $this->assertSame("line one\nline two", PlainText::spaces("line one\nline two"));

        // A family emoji (joiners), a variation selector and a flag with tag characters are kept as they are
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $this->assertSame("Hi {$family}", PlainText::spaces("Hi {$family}"));
        $this->assertSame("Love \u{2764}\u{FE0F}", PlainText::spaces("Love \u{2764}\u{FE0F}"));
        $flag = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";
        $this->assertSame("Go {$flag}", PlainText::spaces("Go {$flag}"));
    }

    public function test_the_three_repairs_fix_the_real_eval_strings(): void
    {
        foreach ([
            'Please use Messages,o tawagan kami.' => 'Please use Messages, o tawagan kami.',
            'Your session is on Tue,Nov 3 at 2:00PM.' => 'Your session is on Tue, Nov 3 at 2:00 PM.',
            'We are closed on Sundays.We open on Monday.' => 'We are closed on Sundays. We open on Monday.',
            'Note:Bring a valid ID.' => 'Note: Bring a valid ID.',
            'Open from 9am to 6pm;Sundays closed.' => 'Open from 9 am to 6 pm; Sundays closed.',
            'Thank you!See you soon.' => 'Thank you! See you soon.',
        ] as $raw => $fixed) {
            $this->assertSame($fixed, PlainText::repair($raw), $raw);
        }
    }

    public function test_the_repairs_never_touch_numbers_references_addresses_or_abbreviations(): void
    {
        foreach (['₱1,200.00', '3:00 PM', 'https://example.test/a,b', 'eval-shop@example.test', 'e.g. Monday', 'e.g.Monday', 'U.S. dollars',
            'PR-000123', 'No. 5', 'Mr. Cruz', 'GCash', 'DocumentPrinting', 'minutesfor', 'closed onSundays', 'file.PDF', 'ratio 2:1', '10:30',
            'www.example.test/a,b', 'v1.2.3', 'PB-000045 is ready.'] as $text) {
            $this->assertSame($text, PlainText::repair($text), $text);
        }
    }

    public function test_the_validator_applies_the_repairs_to_every_reply(): void
    {
        $this->assertSame('We are closed on Sundays. We open on Monday, 9:00 AM.', app(ResponseValidator::class)->plainText("We are closed on\u{00A0}Sundays.We open on Monday, 9:00AM."));
    }

    public function test_a_dangling_colon_line_is_removed(): void
    {
        $this->assertSame('We print photos and documents.', ReplyCleanup::finish('We print photos and documents. Here are the options:', 'fallback'));
        $this->assertSame("Our packages:\n- Solo\n- Family", ReplyCleanup::finish("Our packages:\n- Solo\n- Family", 'fallback'));
        $this->assertSame('Note: bring an ID. We open at 9:00 AM.', ReplyCleanup::finish('Note: bring an ID. We open at 9:00 AM.', 'fallback'));
        $this->assertSame('Pickup is at 3:00', ReplyCleanup::finish('Pickup is at 3:00', 'fallback'));
        $this->assertSame("We have two options.\nFirst we print.", ReplyCleanup::finish("We have two options.\nFirst we print.\nThe steps are:", 'fallback'));
        $this->assertSame('fallback', ReplyCleanup::finish('Here is what you need:', 'fallback'));
    }

    public function test_guard_stats_count_removed_sentences_without_text(): void
    {
        $stats = app(GuardStats::class);
        $stats->reset();

        app(ClaimGuard::class)->guard('I cancelled your order. We print photos.', CustomerRecords::none());
        app(PriceGuard::class)->guard('It costs ₱9,999.00.', new ProviderRequest('system', [new ProviderMessage(ProviderMessage::USER, 'q')], AssistantFeature::AskLumi));
        ReplyCleanup::finish('We print photos. The steps are:', 'fallback');

        $snapshot = $stats->snapshot();
        $this->assertSame(1, $snapshot['removed'][GuardStats::CLAIM]);
        $this->assertSame(1, $snapshot['removed'][GuardStats::PRICE]);
        $this->assertSame(1, $snapshot['removed'][GuardStats::DANGLING_COLON]);
        $this->assertSame(1, $snapshot['replaced'][GuardStats::PRICE] ?? 0, 'The price reply had only that sentence');
        $this->assertArrayNotHasKey(GuardStats::CLAIM, $snapshot['replaced']);
        $this->assertStringNotContainsString('cancelled', json_encode($snapshot));

        $stats->reset();
        $this->assertSame(['removed' => [], 'replaced' => []], $stats->snapshot());
    }

    public function test_english_after_may_is_english_and_taglish_stays_taglish(): void
    {
        $this->assertSame('en', MessageLanguage::detect('Good afternoon. May I kindly ask what file formats you accept for photo printing?'));
        $this->assertSame('en', MessageLanguage::detect('May I ask how long the printing usually takes?'));
        $this->assertSame('en', MessageLanguage::detect('What do I need to bring on the day of the shoot?'));
        $this->assertSame('fil', MessageLanguage::detect('May bayad ba ang rush?'));
        $this->assertNotSame('en', MessageLanguage::detect('Pwede ba mag-book this Saturday? Gusto ko sana yung family package.'));
        $this->assertSame('taglish', MessageLanguage::detect('May available slot ba kayo this Saturday for the family package?'));
    }
}
