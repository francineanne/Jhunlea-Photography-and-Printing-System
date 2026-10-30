<?php

namespace Tests\Feature\Public;

use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicDataTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return [
            'landing' => ['/'],
            'services' => ['/services'],
            'packages' => ['/packages'],
            'compare' => ['/packages/compare'],
            'finder' => ['/packages/finder'],
        ];
    }

    #[DataProvider('pages')]
    public function test_every_public_page_is_open_to_a_guest_a_customer_and_the_owner(string $url): void
    {
        $this->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->get($url)->assertOk();
        $this->actingAs(User::factory()->owner()->create())->get($url)->assertOk();
    }

    #[DataProvider('pages')]
    public function test_the_pages_work_with_an_empty_catalog(string $url): void
    {
        $this->get($url)->assertOk();
    }

    public function test_a_published_package_detail_page_is_open_to_everyone(): void
    {
        $package = PhotographyPackage::factory()->published()->create(['slug' => 'open-package']);

        $this->get('/packages/open-package')->assertOk()->assertSee($package->name);
        $this->actingAs(User::factory()->create())->get('/packages/open-package')->assertOk();
    }

    // ---- nothing unpublished or deleted shows anywhere ------------------

    public function test_unpublished_and_deleted_packages_never_reach_a_public_list(): void
    {
        PhotographyPackage::factory()->published()->create(['name' => 'Visible Package', 'slug' => 'visible-package', 'category' => 'Visible']);
        PhotographyPackage::factory()->create(['name' => 'Draft Package', 'slug' => 'draft-package', 'category' => 'Draft']);
        PhotographyPackage::factory()->published()->create(['name' => 'Gone Package', 'slug' => 'gone-package', 'category' => 'Gone'])->delete();

        $list = $this->get('/packages')->assertOk();
        $this->assertSame(['Visible Package'], $list->viewData('packages')->pluck('name')->all());
        $this->assertSame(['Visible' => 1], $list->viewData('categories')->all());
        $list->assertDontSee('Draft Package')->assertDontSee('Gone Package');

        $home = $this->get('/')->assertOk();
        $this->assertSame(1, (int) $home->viewData('packageStats')->total);

        $this->get('/packages/draft-package')->assertNotFound();
        $this->get('/packages/gone-package')->assertNotFound();
        $this->get('/packages/no-such-package')->assertNotFound();
    }

    public function test_unpublished_and_deleted_services_never_reach_the_services_page(): void
    {
        PrintingService::factory()->published()->create(['name' => 'Visible Service', 'category' => 'Visible']);
        PrintingService::factory()->create(['name' => 'Draft Service', 'category' => 'Draft']);
        PrintingService::factory()->published()->create(['name' => 'Gone Service', 'category' => 'Gone'])->delete();

        $response = $this->get('/services')->assertOk();

        $this->assertSame(['Visible Service'], $response->viewData('services')->pluck('name')->all());
        $this->assertSame(['Visible' => 1], $response->viewData('categories')->all());
        $response->assertDontSee('Draft Service')->assertDontSee('Gone Service');
        $this->assertSame(1, $this->get('/')->viewData('serviceCategories')->sum());
    }

    public function test_unpublished_themes_never_show_and_only_published_packages_count_for_a_theme(): void
    {
        $shown = Theme::factory()->published()->create(['name' => 'Shown Theme']);
        $hidden = Theme::factory()->create(['name' => 'Hidden Theme']);
        $onlyDraftPackage = Theme::factory()->published()->create(['name' => 'Only Draft Theme']);
        $package = PhotographyPackage::factory()->published()->create(['slug' => 'with-themes']);
        $package->themes()->attach([$shown->id, $hidden->id]);
        PhotographyPackage::factory()->create()->themes()->attach($onlyDraftPackage);

        $this->assertSame(['Shown Theme'], $this->get('/packages')->viewData('themes')->pluck('name')->all());
        $this->assertSame(['Shown Theme'], $this->get('/packages/with-themes')->viewData('package')->themes->pluck('name')->all());
        $this->assertSame(['Shown Theme'], $this->get('/packages/finder')->viewData('themes')->pluck('name')->all());
    }

    public function test_related_packages_are_published_same_category_and_not_the_package_itself(): void
    {
        $main = PhotographyPackage::factory()->published()->create(['slug' => 'main', 'category' => 'Family']);
        PhotographyPackage::factory()->published()->create(['name' => 'Related One', 'category' => 'Family']);
        PhotographyPackage::factory()->create(['name' => 'Draft Family', 'category' => 'Family']);
        PhotographyPackage::factory()->published()->create(['name' => 'Gone Family', 'category' => 'Family'])->delete();
        PhotographyPackage::factory()->published()->create(['name' => 'Other Category', 'category' => 'Portrait']);

        $related = $this->get('/packages/main')->viewData('related');

        $this->assertSame(['Related One'], $related->pluck('name')->all());
        $this->assertFalse($related->contains('id', $main->id));
    }

    public function test_only_active_options_of_published_services_are_loaded(): void
    {
        $service = PrintingService::factory()->published()->create();
        PrintingServiceOption::factory()->create(['printing_service_id' => $service->id, 'label' => 'On option', 'is_active' => true]);
        PrintingServiceOption::factory()->create(['printing_service_id' => $service->id, 'label' => 'Off option', 'is_active' => false]);

        $this->assertSame(['On option'], $this->get('/services')->viewData('services')->first()->options->pluck('label')->all());
    }

    // ---- hostile input --------------------------------------------------

    /** @return array<string, array{string}> */
    public static function hostile(): array
    {
        return [
            'arrays instead of text' => ['?category[]=x&sort[]=y&people[]=1&budget[]=2&theme[]=3&minutes[]=4&q[]=z&page[]=1'],
            'huge numbers' => ['?people=99999999999999999999&minutes=-5&budget=1e999&theme=9999999999999999999999&page=99999999999'],
            'unknown sort' => ['?sort=price;DROP TABLE users'],
            'sql looking text' => ['?category='."' OR 1=1 --".'&q='."'; DROP TABLE users; --"],
            'script tags' => ['?category=<script>alert(1)</script>&q=<img src=x onerror=alert(1)>&packages[]=<script>'],
            'very long text' => ['?category='.str_repeat('a', 5000).'&q='.str_repeat('b', 5000)],
            'percent and underscore' => ['?q=%25&category=_'],
            'negative page' => ['?page=-1'],
        ];
    }

    #[DataProvider('hostile')]
    public function test_hostile_query_strings_never_cause_a_server_error(string $query): void
    {
        PhotographyPackage::factory()->published()->create();
        PrintingService::factory()->published()->create();

        foreach (['/packages', '/services', '/packages/compare', '/packages/finder', '/'] as $url) {
            $status = $this->get($url.$query)->status();

            $this->assertLessThan(500, $status, "{$url}{$query}");
        }
    }

    public function test_text_from_the_address_is_never_reflected_unescaped(): void
    {
        PhotographyPackage::factory()->published()->create();
        $payload = '<script>alert(1)</script>';

        foreach (['/packages?category=', '/services?q=', '/services?category=', '/packages/finder?category='] as $url) {
            $this->get($url.urlencode($payload))->assertOk()->assertDontSee($payload, false);
        }
    }

    public function test_the_search_treats_percent_and_underscore_literally(): void
    {
        PrintingService::factory()->published()->create(['name' => 'Fifty Percent']);

        $this->assertCount(0, $this->get('/services?q=%25')->viewData('services'));
    }

    // ---- throttle -------------------------------------------------------

    public function test_the_public_pages_are_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->get('/packages')->assertOk();
        }

        $this->get('/packages')->assertStatus(429);
    }

    // ---- compare --------------------------------------------------------

    private function published(string $slug, array $overrides = []): PhotographyPackage
    {
        return PhotographyPackage::factory()->published()->create($overrides + ['slug' => $slug, 'name' => ucfirst($slug)]);
    }

    public function test_compare_accepts_two_and_three_packages_in_the_order_given(): void
    {
        $this->published('alpha');
        $this->published('beta');
        $this->published('gamma');

        $two = $this->get('/packages/compare?packages[]=beta&packages[]=alpha')->assertOk();
        $this->assertSame(['beta', 'alpha'], $two->viewData('packages')->pluck('slug')->all());
        $this->assertTrue($two->viewData('ready'));

        $three = $this->get('/packages/compare?packages[]=alpha&packages[]=beta&packages[]=gamma')->assertOk();
        $this->assertCount(3, $three->viewData('packages'));
        $this->assertSame([], $three->viewData('notes'));
    }

    public function test_compare_with_fewer_than_two_is_not_ready(): void
    {
        $this->published('alpha');

        $none = $this->get('/packages/compare')->assertOk();
        $one = $this->get('/packages/compare?packages[]=alpha')->assertOk();

        $this->assertFalse($none->viewData('ready'));
        $this->assertFalse($one->viewData('ready'));
    }

    public function test_compare_keeps_only_the_first_three_and_says_so(): void
    {
        foreach (['a1', 'a2', 'a3', 'a4'] as $slug) {
            $this->published($slug);
        }

        $response = $this->get('/packages/compare?packages[]=a1&packages[]=a2&packages[]=a3&packages[]=a4')->assertOk();

        $this->assertSame(['a1', 'a2', 'a3'], $response->viewData('packages')->pluck('slug')->all());
        $this->assertNotEmpty($response->viewData('notes'));
    }

    public function test_compare_removes_duplicates_and_drops_unpublished_deleted_unknown_and_malformed_addresses(): void
    {
        $this->published('alpha');
        $this->published('beta');
        PhotographyPackage::factory()->create(['slug' => 'draft']);
        $this->published('gone')->delete();

        $response = $this->get('/packages/compare?packages[]=alpha&packages[]=alpha&packages[]=draft&packages[]=gone&packages[]=nope&packages[]=Bad_Slug!&packages[]=beta&packages[][]=x')->assertOk();

        $this->assertSame(['alpha', 'beta'], $response->viewData('packages')->pluck('slug')->all());
        $this->assertTrue($response->viewData('ready'));
        $this->assertNotEmpty($response->viewData('notes'));
        $response->assertDontSee('Draft');
    }

    public function test_compare_with_one_valid_package_left_after_dropping_is_not_ready(): void
    {
        $this->published('alpha');
        PhotographyPackage::factory()->create(['slug' => 'draft']);

        $response = $this->get('/packages/compare?packages[]=alpha&packages[]=draft')->assertOk();

        $this->assertFalse($response->viewData('ready'));
    }

    // ---- filters and sorts ----------------------------------------------

    public function test_the_filters_and_sorts_return_the_right_packages(): void
    {
        $this->published('solo', ['name' => 'Solo', 'category' => 'Portrait', 'max_people' => 1, 'duration_minutes' => 30, 'price' => '1200.00']);
        $this->published('family', ['name' => 'Family', 'category' => 'Family', 'max_people' => 6, 'duration_minutes' => 90, 'price' => '3500.00']);
        $this->published('asked', ['name' => 'Asked', 'category' => 'Family', 'max_people' => 10, 'duration_minutes' => 120, 'price' => null]);
        $names = fn (string $query) => $this->get("/packages?{$query}")->viewData('packages')->pluck('name')->all();

        $this->assertSame(['Asked', 'Family'], $names('category=Family'));
        $this->assertSame(['Asked', 'Family'], $names('people=4'));
        $this->assertSame(['Family', 'Solo'], $names('minutes=90'));
        $this->assertSame(['Solo'], $names('budget=1200'), 'a budget leaves out packages with no listed price');
        $this->assertSame(['Family', 'Solo'], $names('budget=3500'));
        $this->assertSame(['Solo', 'Family', 'Asked'], $names('sort=price_asc'), 'no price goes last');
        $this->assertSame(['Family', 'Solo', 'Asked'], $names('sort=price_desc'), 'no price goes last');
        $this->assertSame(['Solo', 'Family', 'Asked'], $names('sort=duration'));
        $this->assertSame(['Asked', 'Family', 'Solo'], $names('sort=name'));
    }

    public function test_the_default_order_puts_featured_packages_first(): void
    {
        $this->published('plain', ['name' => 'Aaa Plain', 'sort_order' => 0]);
        $this->published('star', ['name' => 'Zzz Star', 'sort_order' => 99, 'is_featured' => true]);

        $this->assertSame(['Zzz Star', 'Aaa Plain'], $this->get('/packages')->viewData('packages')->pluck('name')->all());
    }

    public function test_the_theme_filter_uses_published_themes_only(): void
    {
        $theme = Theme::factory()->published()->create();
        $hidden = Theme::factory()->create();
        $this->published('with', ['name' => 'With'])->themes()->attach($theme);
        $this->published('without', ['name' => 'Without']);
        $this->published('hiddenonly', ['name' => 'HiddenOnly'])->themes()->attach($hidden);

        $this->assertSame(['With'], $this->get("/packages?theme={$theme->id}")->viewData('packages')->pluck('name')->all());
        $this->assertSame([], $this->get("/packages?theme={$hidden->id}")->viewData('packages')->pluck('name')->all());
    }

    public function test_pagination_keeps_the_query_string_and_ignores_unfiltered_values(): void
    {
        foreach (range(1, 11) as $i) {
            $this->published("pkg-{$i}", ['category' => 'Portrait', 'name' => sprintf('Pkg %02d', $i)]);
        }

        $page = $this->get('/packages?category=Portrait&sort=name&nonsense=1')->assertOk();

        $this->assertSame(9, $page->viewData('packages')->count());
        $this->assertStringContainsString('category=Portrait', $page->viewData('packages')->nextPageUrl());
        $this->assertStringContainsString('sort=name', $page->viewData('packages')->nextPageUrl());
        $this->assertSame(2, $this->get('/packages?category=Portrait&page=2')->viewData('packages')->count());
    }

    // ---- reserved addresses ---------------------------------------------

    public function test_a_package_cannot_take_an_address_the_website_uses(): void
    {
        $owner = User::factory()->owner()->create();
        $data = ['_form' => 'package', 'name' => 'Some Package', 'category' => 'X', 'short_description' => 's', 'duration_minutes' => 30, 'max_people' => 1];

        $this->actingAs($owner)->post('/owner/packages', $data + ['slug' => 'compare'])->assertSessionHasErrors('slug');
        $this->actingAs($owner)->post('/owner/packages', $data + ['slug' => 'finder'])->assertSessionHasErrors('slug');
        $this->assertSame('compare-2', PhotographyPackage::uniqueSlug('Compare'));
        $this->assertSame('finder-2', PhotographyPackage::uniqueSlug('Finder'));

        $this->actingAs($owner)->post('/owner/packages', ['name' => 'Compare', 'slug' => ''] + $data)->assertSessionHasNoErrors();
        $this->assertSame('compare-2', PhotographyPackage::firstOrFail()->slug);
    }

    // ---- query counts ---------------------------------------------------

    public function test_the_pages_use_a_fixed_small_number_of_queries(): void
    {
        foreach (range(1, 12) as $i) {
            $package = $this->published("many-{$i}", ['is_featured' => $i <= 3]);
            $package->themes()->attach(Theme::factory()->published()->create());
        }
        PrintingService::factory()->published()->count(5)->create()->each(fn ($service) => PrintingServiceOption::factory()->count(3)->create(['printing_service_id' => $service->id]));

        $counts = [];

        // Each page's own queries, plus 2 for the footer since Phase 6B (the shop's contact details and whether a FAQ is published)
        foreach (['/' => 7, '/services' => 7, '/packages' => 8, '/packages/many-1' => 7, '/packages/compare?packages[]=many-1&packages[]=many-2&packages[]=many-3' => 7, '/packages/finder?category=X&people=2' => 8, '/faq' => 3] as $url => $limit) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();
            $counts[$url] = count(DB::getQueryLog());

            $this->assertLessThanOrEqual($limit, $counts[$url], "{$url} used {$counts[$url]} queries");
        }
    }
}
