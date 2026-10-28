<?php

namespace Tests\Feature\Public;

use App\Models\PackageImage;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private function package(array $attributes = []): PhotographyPackage
    {
        return PhotographyPackage::factory()->published()->create($attributes + ['slug' => 'detail']);
    }

    public function test_it_shows_every_public_detail(): void
    {
        $this->package([
            'name' => 'Family Day', 'category' => 'Family', 'short_description' => 'A relaxed family session.',
            'description' => 'Long text about the day.', 'price' => '3500.00', 'price_requires_confirmation' => true,
            'duration_minutes' => 90, 'max_people' => 6, 'digital_outputs' => 15,
            'inclusions' => ['Printed frame', 'Online gallery'], 'terms' => 'Arrive ten minutes early.',
        ]);

        $this->get('/packages/detail')->assertOk()
            ->assertSee('Family Day')->assertSee('A relaxed family session.')->assertSee('Long text about the day.')
            ->assertSee('From ₱3,500.00 — final price confirmed after review')
            ->assertSee('90 mins')->assertSee('Up to 6 people')->assertSee('15 edited photos')
            ->assertSee('Printed frame')->assertSee('Online gallery')->assertSee('Arrive ten minutes early.')
            ->assertSee('Payment is made face-to-face at Jhunlea Photography &amp; Printing.', false);
    }

    public function test_the_three_kinds_of_price_text_appear(): void
    {
        $this->package(['price' => '1200.00', 'price_requires_confirmation' => false]);
        $this->get('/packages/detail')->assertSee('₱1,200.00')->assertDontSee('final price confirmed after review  ');

        PhotographyPackage::query()->update(['price' => null]);
        $this->get('/packages/detail')->assertSee('Price on request');
    }

    public function test_only_published_themes_show_with_their_swatches(): void
    {
        $package = $this->package();
        $package->themes()->attach(Theme::factory()->published()->create(['name' => 'Garden Party', 'color_swatches' => ['#112233', '#445566']]));
        $package->themes()->attach(Theme::factory()->create(['name' => 'Secret Draft Theme']));

        $this->get('/packages/detail')->assertSee('Garden Party')->assertSee('#112233')->assertDontSee('Secret Draft Theme');
    }

    public function test_the_gallery_lists_every_photo_with_dimensions_and_alt_text(): void
    {
        $package = $this->package(['name' => 'Detail']);
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'is_cover' => true, 'sort_order' => 0, 'path' => 'packages/one.jpg', 'alt_text' => 'The first photo']);
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'is_cover' => false, 'sort_order' => 1, 'path' => 'packages/two.jpg']);

        $html = $this->get('/packages/detail')->assertOk()->getContent();

        $this->assertStringContainsString('packages/one.jpg', $html);
        $this->assertStringContainsString('packages/two.jpg', $html);
        $this->assertStringContainsString('alt="The first photo"', $html);
        $this->assertStringContainsString('Photo 2 of Detail', $html);
        $this->assertStringContainsString('aria-label="Next photo"', $html);
        $this->assertStringContainsString('width="1200" height="900"', $html);
    }

    public function test_a_package_without_photos_shows_a_placeholder_and_no_gallery_controls(): void
    {
        $this->package();

        $this->get('/packages/detail')->assertOk()->assertDontSee('Next photo');
    }

    public function test_it_has_its_own_title_description_canonical_and_share_image(): void
    {
        $package = $this->package(['name' => 'Unique Name', 'short_description' => 'Short text for search engines.']);
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'is_cover' => true, 'path' => 'packages/share.jpg']);

        $page = $this->get('/packages/detail')->assertOk();

        $page->assertSee('<title>Unique Name | '.e(config('app.name')).'</title>', false);
        $page->assertSee('<meta name="description" content="Short text for search engines.">', false);
        $page->assertSee('<link rel="canonical" href="'.url('/packages/detail').'">', false);
        $page->assertSee('og:image', false)->assertSee('packages/share.jpg', false);
    }

    public function test_an_unknown_unpublished_or_deleted_package_gets_the_styled_404(): void
    {
        PhotographyPackage::factory()->create(['slug' => 'draft', 'name' => 'Draft Secret']);
        PhotographyPackage::factory()->published()->create(['slug' => 'gone', 'name' => 'Gone Secret'])->delete();

        foreach (['draft', 'gone', 'missing', 'Bad_Slug', 'a%20b'] as $slug) {
            $this->get("/packages/{$slug}")->assertNotFound()->assertDontSee('Secret');
        }
    }

    public function test_no_owner_only_field_is_in_the_page(): void
    {
        $this->package(['sort_order' => 4242]);

        $this->get('/packages/detail')->assertDontSee('is_published')->assertDontSee('4242')->assertDontSee('internal');
    }
}
