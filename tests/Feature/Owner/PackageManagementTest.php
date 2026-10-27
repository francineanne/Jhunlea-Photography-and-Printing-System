<?php

namespace Tests\Feature\Owner;

use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PackageManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            '_form' => 'package',
            'name' => 'Family Warmth',
            'slug' => '',
            'category' => 'Family',
            'short_description' => 'Group portraits for the whole family.',
            'description' => 'A longer description.',
            'price' => '3500.00',
            'price_requires_confirmation' => '0',
            'duration_minutes' => 90,
            'max_people' => 6,
            'digital_outputs' => 20,
            'inclusions' => "90 minutes in the studio\nEdited digital photos",
            'terms' => 'Arrive early.',
            'sort_order' => 5,
            'is_published' => '1',
        ], $overrides);
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    // ---- access control -------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function routes(): array
    {
        return [
            'index' => ['get', '/owner/packages'],
            'create' => ['get', '/owner/packages/create'],
            'store' => ['post', '/owner/packages'],
            'edit' => ['get', '/owner/packages/1/edit'],
            'update' => ['put', '/owner/packages/1'],
            'destroy' => ['delete', '/owner/packages/1'],
            'publish' => ['post', '/owner/packages/1/publish'],
            'feature' => ['post', '/owner/packages/1/feature'],
        ];
    }

    #[DataProvider('routes')]
    public function test_a_guest_is_sent_to_the_login_page(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }

    #[DataProvider('routes')]
    public function test_a_customer_gets_403_even_for_ids_that_do_not_exist(string $method, string $url): void
    {
        $this->actingAs(User::factory()->create())->{$method}($url)->assertForbidden();
    }

    public function test_the_sidebar_marks_packages_as_current(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/owner/packages')->assertOk()->assertSee('aria-current="page"', false);
        $this->actingAs($owner)->get('/owner/packages/create')->assertOk()->assertSee('aria-current="page"', false);
    }

    // ---- create ---------------------------------------------------------

    public function test_the_owner_can_create_a_package_with_themes(): void
    {
        $themes = Theme::factory()->count(2)->create();

        $response = $this->actingAs($this->owner())
            ->post('/owner/packages', $this->validData(['theme_ids' => $themes->pluck('id')->all()]));

        $package = PhotographyPackage::firstOrFail();
        $response->assertRedirect("/owner/packages/{$package->id}/edit");
        $this->assertSame('family-warmth', $package->slug);
        $this->assertSame('3500.00', $package->price);
        $this->assertSame(['90 minutes in the studio', 'Edited digital photos'], $package->inclusions);
        $this->assertTrue($package->is_published);
        $this->assertFalse($package->is_featured);
        $this->assertEqualsCanonicalizing($themes->pluck('id')->all(), $package->themes()->pluck('themes.id')->all());
    }

    public function test_inclusions_are_trimmed_and_blank_lines_are_dropped(): void
    {
        $this->actingAs($this->owner())->post('/owner/packages', $this->validData(['inclusions' => "  One  \r\n\r\n   \nTwo\r\n"]));

        $this->assertSame(['One', 'Two'], PhotographyPackage::firstOrFail()->inclusions);
    }

    public function test_a_package_can_have_no_price_and_no_inclusions(): void
    {
        $this->actingAs($this->owner())->post('/owner/packages', $this->validData(['price' => '', 'digital_outputs' => '', 'inclusions' => '']))
            ->assertSessionHasNoErrors();

        $package = PhotographyPackage::firstOrFail();
        $this->assertNull($package->price);
        $this->assertNull($package->digital_outputs);
        $this->assertSame([], $package->inclusions);
        $this->assertSame('Price on request', $package->priceText());
    }

    // ---- validation -----------------------------------------------------

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalid(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'missing category' => [['category' => ''], 'category'],
            'missing summary' => [['short_description' => ''], 'short_description'],
            'negative price' => [['price' => '-1'], 'price'],
            'too many decimals' => [['price' => '10.123'], 'price'],
            'price too large' => [['price' => '100000000'], 'price'],
            'price not a number' => [['price' => 'free'], 'price'],
            'zero minutes' => [['duration_minutes' => 0], 'duration_minutes'],
            'too many minutes' => [['duration_minutes' => 1441], 'duration_minutes'],
            'zero people' => [['max_people' => 0], 'max_people'],
            'negative outputs' => [['digital_outputs' => -1], 'digital_outputs'],
            'inclusions as array of arrays' => [['inclusions' => [['x']]], 'inclusions.0'],
            'unsafe slug' => [['slug' => 'Bad_Slug!'], 'slug'],
            'themes not an array' => [['theme_ids' => 'abc'], 'theme_ids'],
            'unknown theme id' => [['theme_ids' => [9999]], 'theme_ids.0'],
            'theme id not a number' => [['theme_ids' => ['x']], 'theme_ids.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalid')]
    public function test_bad_input_is_rejected_and_nothing_is_saved(array $overrides, string $field): void
    {
        $this->actingAs($this->owner())->post('/owner/packages', $this->validData($overrides))->assertSessionHasErrors($field);

        $this->assertSame(0, PhotographyPackage::count());
    }

    public function test_too_many_or_too_long_inclusions_are_rejected(): void
    {
        $owner = $this->owner();

        $thirteen = implode("\n", array_map(fn ($i) => "Item {$i}", range(1, 13)));
        $this->actingAs($owner)->post('/owner/packages', $this->validData(['inclusions' => $thirteen]))->assertSessionHasErrors('inclusions');

        $this->actingAs($owner)->post('/owner/packages', $this->validData(['inclusions' => str_repeat('x', 121)]))->assertSessionHasErrors('inclusions.0');

        $twelve = implode("\n", array_fill(0, 12, str_repeat('y', 120)));
        $this->actingAs($owner)->post('/owner/packages', $this->validData(['inclusions' => $twelve]))->assertSessionHasNoErrors();
    }

    public function test_a_soft_deleted_themes_id_is_not_accepted(): void
    {
        $theme = Theme::factory()->create();
        $theme->delete();

        $this->actingAs($this->owner())->post('/owner/packages', $this->validData(['theme_ids' => [$theme->id]]))->assertSessionHasErrors('theme_ids.0');
    }

    public function test_the_form_keeps_what_was_typed_after_an_error(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->from('/owner/packages/create')->post('/owner/packages', $this->validData(['price' => '-5', 'inclusions' => "Kept one\nKept two"]))
            ->assertRedirect('/owner/packages/create');

        $this->actingAs($owner)->get('/owner/packages/create')
            ->assertSee('Family Warmth')
            ->assertSee("Kept one\nKept two", false);
    }

    public function test_the_address_follows_the_name_and_gets_a_number_when_taken(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/owner/packages', $this->validData());
        $this->actingAs($owner)->post('/owner/packages', $this->validData(['slug' => 'family-warmth']));

        $this->assertEqualsCanonicalizing(['family-warmth', 'family-warmth-2'], PhotographyPackage::pluck('slug')->all());
    }

    public function test_a_hand_typed_address_that_is_taken_is_rejected(): void
    {
        PhotographyPackage::factory()->create(['slug' => 'taken']);

        $this->actingAs($this->owner())->post('/owner/packages', $this->validData(['slug' => 'taken']))->assertSessionHasErrors('slug');
    }

    public function test_server_fields_cannot_be_forced_through_the_request(): void
    {
        $this->actingAs($this->owner())->post('/owner/packages', $this->validData(['id' => 999, 'created_at' => '2000-01-01', 'deleted_at' => '2000-01-01']));

        $package = PhotographyPackage::firstOrFail();
        $this->assertNotSame(999, $package->id);
        $this->assertNull($package->deleted_at);
        $this->assertNotSame('2000-01-01', $package->created_at->format('Y-m-d'));
    }

    // ---- edit, publish, feature, delete ---------------------------------

    public function test_the_edit_page_shows_the_package_with_its_inclusions_and_themes(): void
    {
        $package = PhotographyPackage::factory()->create(['inclusions' => ['Alpha item', 'Beta item']]);
        $theme = Theme::factory()->create(['name' => 'Rose Backdrop']);
        $package->themes()->attach($theme);

        $this->actingAs($this->owner())->get("/owner/packages/{$package->id}/edit")
            ->assertOk()
            ->assertSee($package->name)
            ->assertSee("Alpha item\nBeta item", false)
            ->assertSee('Rose Backdrop')
            ->assertSee('Customer view preview');
    }

    public function test_the_owner_can_edit_a_package_and_change_its_themes(): void
    {
        [$keep, $drop, $add] = Theme::factory()->count(3)->create()->all();
        $package = PhotographyPackage::factory()->create(['name' => 'Old', 'slug' => 'old']);
        $package->themes()->sync([$keep->id, $drop->id]);

        $this->actingAs($this->owner())
            ->put("/owner/packages/{$package->id}", $this->validData(['name' => 'Renamed', 'slug' => '', 'price' => '99', 'theme_ids' => [$keep->id, $add->id]]))
            ->assertRedirect("/owner/packages/{$package->id}/edit");

        $package->refresh();
        $this->assertSame('Renamed', $package->name);
        $this->assertSame('old', $package->slug, 'a blank address keeps the current one, so links stay stable');
        $this->assertSame('99.00', $package->price);
        $this->assertEqualsCanonicalizing([$keep->id, $add->id], $package->themes()->pluck('themes.id')->all());
    }

    public function test_saving_without_any_theme_removes_the_links(): void
    {
        $package = PhotographyPackage::factory()->create();
        $package->themes()->attach(Theme::factory()->create());

        $this->actingAs($this->owner())->put("/owner/packages/{$package->id}", $this->validData());

        $this->assertSame(0, DB::table('package_theme')->count());
    }

    public function test_publish_and_feature_toggle_and_keep_the_filters(): void
    {
        $package = PhotographyPackage::factory()->create();
        $owner = $this->owner();

        $this->actingAs($owner)->from('/owner/packages?search=a')->post("/owner/packages/{$package->id}/publish")->assertRedirect('/owner/packages?search=a');
        $this->assertTrue($package->fresh()->is_published);

        $this->actingAs($owner)->post("/owner/packages/{$package->id}/publish");
        $this->assertFalse($package->fresh()->is_published);

        $this->actingAs($owner)->post("/owner/packages/{$package->id}/feature");
        $this->assertTrue($package->fresh()->is_featured);
        $this->actingAs($owner)->post("/owner/packages/{$package->id}/feature");
        $this->assertFalse($package->fresh()->is_featured);
    }

    public function test_deleting_is_a_soft_delete_and_the_package_leaves_the_pages(): void
    {
        $package = PhotographyPackage::factory()->create(['name' => 'Gone Package']);
        $owner = $this->owner();

        $this->actingAs($owner)->delete("/owner/packages/{$package->id}")->assertRedirect('/owner/packages');

        $this->assertSoftDeleted('photography_packages', ['id' => $package->id]);
        $this->actingAs($owner)->get("/owner/packages/{$package->id}/edit")->assertNotFound();
        $this->actingAs($owner)->get('/owner/packages')->assertDontSee('Gone Package');
    }

    // ---- list -----------------------------------------------------------

    public function test_the_list_shows_the_three_price_cases(): void
    {
        PhotographyPackage::factory()->create(['name' => 'Confirmed', 'price' => '1850', 'price_requires_confirmation' => true]);
        PhotographyPackage::factory()->create(['name' => 'Plain', 'price' => '1200.5']);
        PhotographyPackage::factory()->create(['name' => 'Asked', 'price' => null]);

        $this->actingAs($this->owner())->get('/owner/packages')
            ->assertSee('From ₱1,850.00 — final price confirmed after review')
            ->assertSee('₱1,200.50')
            ->assertSee('Price on request');
    }

    public function test_search_category_status_and_featured_filters(): void
    {
        PhotographyPackage::factory()->published()->featured()->create(['name' => 'Alpha Portrait', 'category' => 'Portrait']);
        PhotographyPackage::factory()->create(['name' => 'Beta Family', 'category' => 'Family']);
        $owner = $this->owner();

        $this->actingAs($owner)->get('/owner/packages?search=Alpha')->assertSee('Alpha Portrait')->assertDontSee('Beta Family');
        $this->actingAs($owner)->get('/owner/packages?category=Family')->assertSee('Beta Family')->assertDontSee('Alpha Portrait');
        $this->actingAs($owner)->get('/owner/packages?status=published')->assertSee('Alpha Portrait')->assertDontSee('Beta Family');
        $this->actingAs($owner)->get('/owner/packages?status=draft')->assertSee('Beta Family')->assertDontSee('Alpha Portrait');
        $this->actingAs($owner)->get('/owner/packages?featured=1')->assertSee('Alpha Portrait')->assertDontSee('Beta Family');
        $this->actingAs($owner)->get('/owner/packages?search=nothing-like-this')->assertSee('No packages found');
    }

    public function test_the_search_treats_percent_and_underscore_literally(): void
    {
        PhotographyPackage::factory()->create(['name' => 'Fifty Percent']);

        $this->actingAs($this->owner())->get('/owner/packages?search=%25')->assertSee('No packages found');
    }

    public function test_array_query_values_do_not_crash_the_list(): void
    {
        $this->actingAs($this->owner())->get('/owner/packages?search[]=x&category[]=y&status[]=z&featured[]=1')->assertOk();
    }

    public function test_the_category_chips_count_the_whole_catalog(): void
    {
        PhotographyPackage::factory()->count(2)->create(['category' => 'Portrait']);
        PhotographyPackage::factory()->create(['category' => 'Family']);
        PhotographyPackage::factory()->create(['category' => 'Family'])->delete();

        $response = $this->actingAs($this->owner())->get('/owner/packages?category=Portrait')->assertOk();

        $this->assertSame(['Family' => 1, 'Portrait' => 2], $response->viewData('categories')->all());
    }

    public function test_a_purely_numeric_category_chip_is_marked_current(): void
    {
        PhotographyPackage::factory()->create(['category' => '2024']);

        $this->actingAs($this->owner())->get('/owner/packages?category=2024')->assertOk()->assertSee('aria-current="true"', false);
    }

    public function test_the_list_is_paginated_and_keeps_the_filters(): void
    {
        PhotographyPackage::factory()->count(14)->create(['category' => 'Portrait']);

        $this->actingAs($this->owner())->get('/owner/packages?category=Portrait&page=2')
            ->assertOk()
            ->assertSee('category=Portrait', false);
    }

    public function test_the_list_uses_a_fixed_number_of_queries_however_many_packages_there_are(): void
    {
        PhotographyPackage::factory()->count(10)->create();
        $owner = $this->owner();

        DB::enableQueryLog();
        $this->actingAs($owner)->get('/owner/packages')->assertOk();
        $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'photography_packages') || str_contains($q['query'], 'package_images'))->count();

        $this->assertLessThanOrEqual(5, $count);
    }

    // ---- stat tiles -----------------------------------------------------

    /** @return array<string, mixed> */
    private function stats(array $query = []): array
    {
        return $this->actingAs($this->owner())->get('/owner/packages?'.http_build_query($query))->assertOk()->viewData('stats');
    }

    public function test_an_empty_catalog_shows_zeros(): void
    {
        $this->assertSame(['total' => 0, 'published' => 0, 'unpublished' => 0, 'featured' => 0, 'priced' => 0, 'average' => null, 'min' => null, 'max' => null], $this->stats());
    }

    public function test_the_tiles_count_published_unpublished_featured_and_prices(): void
    {
        PhotographyPackage::factory()->published()->featured()->create(['price' => '1000.00']);
        PhotographyPackage::factory()->published()->create(['price' => '3000.00']);
        PhotographyPackage::factory()->create(['price' => null]);

        $stats = $this->stats();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['published']);
        $this->assertSame(1, $stats['unpublished']);
        $this->assertSame(1, $stats['featured']);
        $this->assertSame(2, $stats['priced']);
        $this->assertEquals(2000.0, (float) $stats['average']);
        $this->assertEquals(1000.0, (float) $stats['min']);
        $this->assertEquals(3000.0, (float) $stats['max']);
    }

    public function test_soft_deleted_packages_are_not_counted_and_filters_do_not_change_the_tiles(): void
    {
        PhotographyPackage::factory()->published()->featured()->create(['name' => 'Keep', 'price' => '100.00']);
        PhotographyPackage::factory()->published()->featured()->create(['price' => '900.00'])->delete();

        $all = $this->stats();
        $filtered = $this->stats(['search' => 'Keep', 'status' => 'draft']);

        $this->assertSame(1, $all['total']);
        $this->assertSame(1, $all['featured']);
        $this->assertEquals(100.0, (float) $all['average']);
        $this->assertSame($all, $filtered);
    }

    public function test_the_tiles_add_one_query(): void
    {
        PhotographyPackage::factory()->count(5)->create();
        $owner = $this->owner();

        DB::enableQueryLog();
        $this->actingAs($owner)->get('/owner/packages')->assertOk();
        $statsQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'COUNT(price)'));

        $this->assertCount(1, $statsQueries);
    }

    public function test_the_form_pages_render_with_their_preview(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/owner/packages/create')->assertOk()->assertSee('Customer view preview')->assertSee('Package name');
    }
}
