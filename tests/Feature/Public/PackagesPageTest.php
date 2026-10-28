<?php

namespace Tests\Feature\Public;

use App\Models\PackageImage;
use App\Models\PhotographyPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackagesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_card_shows_the_public_details_and_the_three_kinds_of_price_text(): void
    {
        PhotographyPackage::factory()->published()->create(['name' => 'Fixed', 'slug' => 'fixed', 'price' => '1500.00', 'price_requires_confirmation' => false, 'inclusions' => ['One backdrop', 'Ten edited photos']]);
        PhotographyPackage::factory()->published()->create(['name' => 'Confirmed', 'slug' => 'confirmed', 'price' => '2500.00', 'price_requires_confirmation' => true]);
        PhotographyPackage::factory()->published()->create(['name' => 'Asked', 'slug' => 'asked', 'price' => null]);

        $this->get('/packages')->assertOk()
            ->assertSee('₱1,500.00')
            ->assertSee('From ₱2,500.00 — final price confirmed after review')
            ->assertSee('Price on request')
            ->assertSee('One backdrop')
            ->assertSee(route('packages.show', 'fixed'), false);
    }

    public function test_the_cover_photo_is_used_when_there_is_one_and_a_placeholder_when_not(): void
    {
        $with = PhotographyPackage::factory()->published()->create(['slug' => 'with-photo']);
        PackageImage::factory()->create(['photography_package_id' => $with->id, 'is_cover' => true, 'path' => 'packages/cover-test.jpg']);
        PhotographyPackage::factory()->published()->create(['slug' => 'no-photo']);

        $html = $this->get('/packages')->assertOk()->getContent();

        $this->assertStringContainsString('packages/cover-test.jpg', $html);
        $this->assertStringContainsString('width="800" height="500"', $html);
    }

    public function test_it_offers_a_compare_checkbox_per_card_and_never_a_booking_button(): void
    {
        PhotographyPackage::factory()->published()->create(['slug' => 'one']);

        $this->get('/packages')->assertSee('name="packages[]" value="one"', false)
            ->assertSee(route('packages.compare'), false)
            ->assertDontSee('Book')
            ->assertDontSee('internal_notes');
    }

    public function test_the_category_chip_links_keep_the_other_filters(): void
    {
        PhotographyPackage::factory()->published()->create(['category' => 'Family']);
        PhotographyPackage::factory()->published()->create(['category' => 'Portrait']);

        $this->get('/packages?people=2&sort=name')->assertSee(e(route('packages.index', ['category' => 'Portrait', 'people' => 2, 'sort' => 'name'])), false);
    }

    public function test_a_filter_with_no_result_says_so_and_offers_a_way_back(): void
    {
        PhotographyPackage::factory()->published()->create(['max_people' => 2]);

        $this->get('/packages?people=50')->assertOk()->assertSee('No package fits those choices')->assertSee(route('packages.index'), false);
    }

    public function test_a_filtered_page_is_not_indexed_and_points_to_the_plain_address(): void
    {
        PhotographyPackage::factory()->published()->create();

        $this->get('/packages?people=2')->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.url('/packages').'">', false);
        $this->get('/packages')->assertDontSee('noindex');
    }

    public function test_a_page_past_the_end_goes_to_the_last_page(): void
    {
        foreach (range(1, 10) as $i) {
            PhotographyPackage::factory()->published()->create(['slug' => "p-{$i}"]);
        }

        $this->get('/packages?page=99')->assertRedirect(url('/packages?page=2'));
        $this->get('/services?page=99')->assertOk();
    }

    public function test_the_category_in_the_address_matches_whatever_letter_case_it_uses(): void
    {
        PhotographyPackage::factory()->published()->create(['category' => 'Wedding']);

        $page = $this->get('/packages?category=wedding')->assertOk();

        $this->assertSame('Wedding', $page->viewData('filters')->category);
        $this->assertSame('Wedding', $this->get('/packages/finder?category=WEDDING')->viewData('answers')->category);
    }

    public function test_the_cover_photo_is_the_first_photo_on_the_detail_page(): void
    {
        $package = PhotographyPackage::factory()->published()->create(['slug' => 'cover-first']);
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'is_cover' => false, 'sort_order' => 0, 'path' => 'packages/a.jpg']);
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'is_cover' => true, 'sort_order' => 1, 'path' => 'packages/b.jpg']);

        $html = $this->get('/packages/cover-first')->getContent();

        $this->assertLessThan(strpos($html, 'packages/a.jpg'), strpos($html, 'packages/b.jpg'));
    }

    public function test_it_explains_that_a_budget_leaves_out_packages_priced_on_request(): void
    {
        PhotographyPackage::factory()->published()->create();

        $this->get('/packages')->assertSee('packages whose price is only given on request are left out');
    }
}
