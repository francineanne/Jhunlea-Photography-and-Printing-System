<?php

namespace Tests\Feature\Public;

use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_real_counts_prices_and_featured_packages(): void
    {
        PhotographyPackage::factory()->published()->create(['name' => 'Star Package', 'slug' => 'star', 'is_featured' => true, 'price' => '1800.00', 'price_requires_confirmation' => false]);
        PhotographyPackage::factory()->published()->create(['name' => 'Cheap Package', 'price' => '950.00']);
        PhotographyPackage::factory()->create(['name' => 'Draft Package', 'price' => '100.00', 'is_featured' => true]);
        PrintingService::factory()->published()->create(['category' => 'Documents']);
        PrintingService::factory()->published()->create(['category' => 'Documents']);

        $page = $this->get('/')->assertOk();

        $page->assertSee('2 packages to choose from')->assertSee('₱950.00')->assertSee('Star Package')->assertSee(route('packages.show', 'star'), false);
        $page->assertSee('Documents')->assertDontSee('Draft Package')->assertDontSee('₱100.00');
        $this->assertSame(['Star Package'], $page->viewData('featured')->pluck('name')->all());
    }

    public function test_an_empty_catalog_shows_gentle_text_instead_of_numbers_or_featured_cards(): void
    {
        $this->get('/')->assertOk()->assertSee('Packages will be listed here soon.')->assertSee('Printing services will be listed here soon.')->assertDontSee('Featured packages');
    }

    public function test_it_only_links_to_pages_that_exist_and_has_no_booking_buttons(): void
    {
        $html = $this->get('/')->getContent();

        foreach ([route('packages.index'), route('services.index'), route('packages.finder')] as $url) {
            $this->assertStringContainsString($url, $html);
        }
        $this->assertStringNotContainsString('Book a shoot', $html);
        $this->assertStringNotContainsString('Request service', $html);
    }

    public function test_the_header_marks_the_current_page(): void
    {
        $this->get('/services')->assertSee('aria-current="page"', false);
        $this->get('/packages')->assertSee('aria-current="page"', false);
        $this->get('/packages/finder')->assertSee('aria-current="page"', false);

        $this->assertStringNotContainsString('aria-current="page"', $this->get('/')->getContent());
    }

    public function test_it_has_a_unique_title_a_description_and_share_tags(): void
    {
        $this->get('/')->assertSee('<meta name="description"', false)->assertSee('og:title', false)->assertSee('<link rel="canonical" href="'.url('/').'">', false);
    }

    public function test_it_keeps_the_face_to_face_payment_notice(): void
    {
        $this->get('/')->assertSee('Payment is made face-to-face at Jhunlea Photography &amp; Printing.', false);
    }
}
