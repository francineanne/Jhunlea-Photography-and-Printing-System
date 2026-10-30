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
        ];
    }

    #[DataProvider('kept')]
    public function test_ordinary_numbers_are_kept(string $text): void
    {
        $this->assertSame($text, (new Redactor)->redact($text));
    }
}
