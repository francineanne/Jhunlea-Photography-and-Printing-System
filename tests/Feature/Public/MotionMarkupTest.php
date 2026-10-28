<?php

namespace Tests\Feature\Public;

use App\Models\PackageImage;
use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The motion of the public pages is added by Alpine after it starts. These tests check the server-rendered HTML,
 * which is what a visitor gets when scripts fail: all content and numbers present, nothing hidden in the markup.
 */
class MotionMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_compare_bar_is_visible_in_the_server_html_and_keeps_its_submit_button(): void
    {
        PhotographyPackage::factory()->published()->create(['slug' => 'one', 'name' => 'One']);

        $html = $this->get('/packages')->assertOk()->getContent();

        $this->assertStringContainsString('x-data="packageCompare(3)"', $html);
        $this->assertStringContainsString('data-name="One"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('Tick Compare on 2 or 3 packages, then press Compare packages.', $html);
        // The hidden state is only ever set from Alpine
        $this->assertStringNotContainsString(' inert', $html);
        $this->assertStringNotContainsString('class="pointer-events-none fixed inset-x-0 bottom-4 z-40 px-4 sm:px-6 translate-y-8', $html);
    }

    public function test_the_compare_bar_stays_hidden_until_alpine_starts_but_only_when_scripts_run(): void
    {
        PhotographyPackage::factory()->published()->create();

        $html = $this->get('/packages')->assertOk()->getContent();

        // The `js` class is added by a script in the head, so a visitor without scripts never gets the hiding rule
        $this->assertStringContainsString("document.documentElement.classList.add('js')", $html);
        // The bar carries the marker, and Alpine removes it when it starts
        $this->assertStringContainsString('data-alpine-pending x-bind:data-alpine-pending="false"', $html);
        $this->assertStringNotContainsString('<html lang="en" class="js"', $html);
    }

    public function test_the_pending_rule_applies_only_under_the_js_class(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('html.js [data-alpine-pending]', $css);
        $this->assertDoesNotMatchRegularExpression('/^\s*\[data-alpine-pending\]\s*\{/m', $css);
    }

    public function test_a_card_carries_the_cover_photo_as_a_data_attribute_for_the_bar(): void
    {
        $package = PhotographyPackage::factory()->published()->create(['slug' => 'with-photo']);
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'is_cover' => true, 'path' => 'packages/bar.jpg']);

        $this->get('/packages')->assertSee('data-cover="', false)->assertSee('packages/bar.jpg', false);
    }

    public function test_public_cards_zoom_the_photo_itself_and_do_not_lift(): void
    {
        $package = PhotographyPackage::factory()->published()->create();
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'is_cover' => true]);

        $public = $this->get('/packages')->getContent();

        $this->assertStringContainsString('group-hover:scale-105', $public);
        $this->assertStringNotContainsString('hover:-translate-y-[3px]', $public);
    }

    public function test_the_featured_pill_appears_only_for_a_featured_package(): void
    {
        PhotographyPackage::factory()->published()->create(['slug' => 'star', 'is_featured' => true]);
        PhotographyPackage::factory()->published()->create(['slug' => 'plain', 'is_featured' => false]);

        $this->get('/packages/star')->assertOk()->assertSee('<h1 class=', false)->assertSee('Featured package')->assertSee('animate-pulse', false);
        $this->get('/packages/plain')->assertOk()->assertDontSee('Featured package');
    }

    public function test_the_gallery_shows_the_first_photo_without_scripts_and_hides_the_rest(): void
    {
        $package = PhotographyPackage::factory()->published()->create(['slug' => 'gallery']);
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'is_cover' => true, 'sort_order' => 0, 'path' => 'packages/first.jpg']);
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'is_cover' => false, 'sort_order' => 1, 'path' => 'packages/second.jpg']);

        $html = $this->get('/packages/gallery')->assertOk()->getContent();

        $this->assertStringContainsString('x-data="packageGallery(', $html);
        preg_match_all('/<img src="[^"]*packages\/(first|second)\.jpg"[^>]*>/s', $html, $images);
        $this->assertStringNotContainsString(' hidden', $images[0][0]);
        $this->assertStringContainsString(' hidden', $images[0][1]);
    }

    public function test_the_compare_page_renders_every_row_and_the_difference_switch_without_scripts(): void
    {
        PhotographyPackage::factory()->published()->create(['slug' => 'a', 'name' => 'Alpha', 'duration_minutes' => 30]);
        PhotographyPackage::factory()->published()->create(['slug' => 'b', 'name' => 'Beta', 'duration_minutes' => 60]);

        $html = $this->get('/packages/compare?packages[]=a&packages[]=b')->assertOk()
            ->assertSee('Back to all packages')
            ->assertSee('Show differences only')
            ->assertSee('30 mins')
            ->assertSee('60 mins')
            ->getContent();

        $this->assertStringContainsString('role="switch"', $html);
        $this->assertStringContainsString('x-bind:hidden="true"', $html);

        // Dimming is applied from Alpine only, never in the server markup
        $this->assertStringNotContainsString('class="border-t border-app-border transition-opacity duration-300 opacity-20', $html);
        $this->assertStringContainsString("x-bind:class=\"dim ? 'opacity-20 grayscale' : ''\"", $html);
    }

    public function test_the_meta_description_is_escaped_once(): void
    {
        PhotographyPackage::factory()->published()->create();

        $this->get('/packages')->assertDontSee('&amp;amp;', false);
        $this->get('/')->assertDontSee('&amp;amp;', false);
    }

    public function test_the_landing_page_has_its_real_numbers_in_the_html(): void
    {
        PhotographyPackage::factory()->published()->create(['price' => '1250.00', 'price_requires_confirmation' => false]);
        PhotographyPackage::factory()->published()->create(['price' => '3000.00']);
        PrintingService::factory()->published()->create(['category' => 'Documents']);

        $this->get('/')->assertOk()->assertSee('2 packages to choose from')->assertSee('₱1,250.00')->assertSee('Documents');
    }
}
