<?php

namespace Tests\Unit;

use App\Services\Assistant\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssistantRedactorTest extends TestCase
{
    public static function personal(): array
    {
        return [
            'mobile plain' => ['Call me at 09171234567 please', 'Call me at [phone] please'],
            'mobile with spaces' => ['Text 0917 123 4567', 'Text [phone]'],
            'mobile with dashes' => ['0917-123-4567 po', '[phone] po'],
            'plus 63' => ['+63 917 123 4567', '[phone]'],
            'plus 63 dashes' => ['+63-917-123-4567', '[phone]'],
            '63 no plus' => ['639171234567', '[phone]'],
            'landline' => ['Landline (02) 8123-4567', 'Landline [phone]'],
            'email' => ['Email ko: maria.santos+shop@gmail.com', 'Email ko: [email]'],
            'long digit run' => ['Card 4111111111111111', 'Card [number]'],
            'account number' => ['Account 12345678', 'Account [number]'],
            // 8D: still redacted next to money and percentages
            'landline plain' => ['Tawag kayo sa 8123-4567', 'Tawag kayo sa [phone]'],
            'phone after an amount' => ['Bayad ₱785.00, text 0917 123 4567', 'Bayad ₱785.00, text [phone]'],
            'phone after a percentage' => ['Down 31.4%, call +63 917 123 4567', 'Down 31.4%, call [phone]'],
        ];
    }

    #[DataProvider('personal')]
    public function test_personal_details_are_replaced(string $text, string $expected): void
    {
        $this->assertSame($expected, (new Redactor)->redact($text));
    }

    public static function kept(): array
    {
        return [
            'price' => ['Magkano yung 1,500 na package?'],
            'quantity' => ['I need 250 copies of 12 pages'],
            'date' => ['Pwede ba sa 2026-11-10 ng 10:30?'],
            'reference' => ['Status of PR-000123?'],
            'year' => ['Graduation 2026 photos'],
            // 8D: an amount followed by a percentage once became "₱[phone]%)" in an owner answer
            'amount and percentage' => ['Down ₱785.00 (31.4%) from the day before (₱2,500.00).'],
            'filipino comparison' => ['₱1,715.00, mas mababa ng ₱785.00 (31.4%) kumpara sa kahapon na ₱2,500.00.'],
            'amounts in a list' => ['Cash ₱3,925.00 (4 payments), GCash ₱920.00 (3 payments), walk-in ₱240.00 (2 payments).'],
            'P amounts' => ['P1,715.00 (P785.00 less, 31.4%)'],
            'percentages in brackets' => ['Online 95.1% (₱4,605.00), walk-in 4.9% (₱240.00)'],
            // 8E: a date in brackets (an audit row once read "exported the income CSV [phone], 2 rows)")
            'date in brackets' => ['Exported the income CSV (2026-11-12, 2 rows).'],
            'date range in brackets' => ['Exported the activity log (2026-11-01 to 2026-11-12, 14 rows).'],
        ];
    }

    #[DataProvider('kept')]
    public function test_ordinary_numbers_are_kept(string $text): void
    {
        $this->assertSame($text, (new Redactor)->redact($text));
    }
}
