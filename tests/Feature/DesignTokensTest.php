<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Colour tokens that pages use without a dark: variant must change in dark mode themselves, or the part shows as a light
 * block on a dark page (the package photo placeholders did, through bg-card).
 */
class DesignTokensTest extends TestCase
{
    public function test_the_card_token_has_a_dark_value(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/--color-card:\s*#F5F5F5;/', $css, 'The light value moved; update this test');
        $this->assertSame(1, preg_match('/\n\.dark\s*\{(.*?)\n\}/s', $css, $dark), 'The .dark block was not found');
        $this->assertMatchesRegularExpression('/--color-card:\s*#[0-9A-Fa-f]{6};/', $dark[1]);
        $this->assertStringNotContainsString('--color-card: #F5F5F5', $dark[1]);
    }
}
