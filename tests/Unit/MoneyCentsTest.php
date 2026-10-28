<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyCentsTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            ['0', 0, '0.00'],
            ['0.00', 0, '0.00'],
            ['0.01', 1, '0.01'],
            ['0.05', 5, '0.05'],
            ['0.29', 29, '0.29'],
            ['1.1', 110, '1.10'],
            ['19.99', 1999, '19.99'],
            ['1250', 125000, '1250.00'],
            ['99999999.99', 9999999999, '99999999.99'],
            ['-5.00', -500, '-5.00'],
            ['-0.07', -7, '-0.07'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_a_price_goes_to_whole_centavos_and_back_exactly(string $price, int $cents, string $text): void
    {
        $this->assertSame($cents, Money::toCents($price));
        $this->assertSame($text, Money::fromCents($cents));
    }

    public function test_numbers_are_accepted_as_well_as_strings(): void
    {
        $this->assertSame(1050, Money::toCents(10.5));
        $this->assertSame(1000, Money::toCents(10));
    }
}
