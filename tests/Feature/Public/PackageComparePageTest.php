<?php

namespace Tests\Feature\Public;

use App\Models\PhotographyPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageComparePageTest extends TestCase
{
    use RefreshDatabase;

    private function package(string $slug, array $attributes = []): PhotographyPackage
    {
        return PhotographyPackage::factory()->published()->create($attributes + ['slug' => $slug, 'name' => 'Pkg '.$slug, 'category' => 'Family', 'duration_minutes' => 60, 'max_people' => 4, 'price' => '2000.00', 'price_requires_confirmation' => false, 'digital_outputs' => 10, 'inclusions' => ['Same item']]);
    }

    public function test_it_shows_each_package_and_marks_the_rows_that_differ(): void
    {
        $this->package('a');
        $this->package('b', ['price' => '3000.00']);

        $html = $this->get('/packages/compare?packages[]=a&packages[]=b')->assertOk()
            ->assertSee('Pkg a')->assertSee('Pkg b')->assertSee('₱2,000.00')->assertSee('₱3,000.00')->getContent();

        // Price differs, session length does not
        $this->assertMatchesRegularExpression('/bg-brand\/5[^>]*>\s*<th[^>]*>\s*Estimated price\s*<span[^>]*>Differs/', $html);
        $this->assertDoesNotMatchRegularExpression('/bg-brand\/5[^>]*>\s*<th[^>]*>\s*Session length/', $html);
    }

    public function test_differences_only_hides_rows_that_match(): void
    {
        $this->package('a');
        $this->package('b', ['price' => '3000.00']);

        $this->get('/packages/compare?packages[]=a&packages[]=b&differences=1')->assertOk()
            ->assertSee('Estimated price')->assertDontSee('Session length')->assertDontSee('What is included');
        $this->get('/packages/compare?packages[]=a&packages[]=b')->assertSee('Session length');
    }

    public function test_identical_packages_say_they_match(): void
    {
        $this->package('a');
        $this->package('b');

        $this->get('/packages/compare?packages[]=a&packages[]=b&differences=1')->assertOk()->assertSee('These packages match on every detail above.');
    }

    public function test_each_package_has_a_remove_link_that_keeps_the_others(): void
    {
        $this->package('a');
        $this->package('b');
        $this->package('c');

        $this->get('/packages/compare?packages[]=a&packages[]=b&packages[]=c')
            ->assertSee(e(route('packages.compare', ['packages' => ['b', 'c']])), false)
            ->assertSee(e(route('packages.compare', ['packages' => ['a', 'c']])), false);
    }

    public function test_fewer_than_two_shows_a_friendly_message_with_a_way_to_the_list(): void
    {
        $this->package('a');

        $this->get('/packages/compare?packages[]=a')->assertOk()->assertSee('Choose at least 2 packages to compare')->assertSee(route('packages.index'), false);
        $this->get('/packages/compare')->assertOk()->assertSee('Choose at least 2 packages to compare');
    }

    public function test_more_than_three_is_cut_to_three_with_a_note(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $slug) {
            $this->package($slug);
        }

        $this->get('/packages/compare?packages[]=a&packages[]=b&packages[]=c&packages[]=d')->assertOk()
            ->assertSee('at most 3 packages')->assertDontSee('Pkg d');
    }

    public function test_unpublished_and_deleted_packages_are_dropped_with_a_note_and_never_shown(): void
    {
        $this->package('a');
        $this->package('b');
        PhotographyPackage::factory()->create(['slug' => 'draft', 'name' => 'Secret Draft']);
        $this->package('gone', ['name' => 'Secret Gone'])->delete();

        $this->get('/packages/compare?packages[]=a&packages[]=draft&packages[]=gone&packages[]=b')->assertOk()
            ->assertSee('not available any more')->assertDontSee('Secret');
    }

    public function test_the_page_is_not_indexed(): void
    {
        $this->get('/packages/compare')->assertSee('noindex', false);
    }
}
