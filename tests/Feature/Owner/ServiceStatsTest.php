<?php

namespace Tests\Feature\Owner;

use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceStatsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function stats(array $query = []): array
    {
        $response = $this->actingAs(User::factory()->owner()->create())->get('/owner/services?'.http_build_query($query))->assertOk();

        return $response->viewData('stats');
    }

    public function test_an_empty_catalog_shows_zeros_and_no_price_range(): void
    {
        $stats = $this->stats();

        $this->assertSame(['total' => 0, 'published' => 0, 'unpublished' => 0, 'options' => 0, 'priced' => 0, 'average' => null, 'min' => null, 'max' => null], $stats);

        $this->actingAs(User::factory()->owner()->create())->get('/owner/services')->assertSee('no prices set yet');
    }

    public function test_the_tiles_count_published_unpublished_options_and_prices(): void
    {
        $a = PrintingService::factory()->published()->create(['base_price' => '10.00']);
        PrintingService::factory()->published()->create(['base_price' => '30.00']);
        PrintingService::factory()->quotation()->create(['is_published' => false]);
        PrintingServiceOption::factory()->count(3)->create(['printing_service_id' => $a->id]);

        $stats = $this->stats();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['published']);
        $this->assertSame(1, $stats['unpublished']);
        $this->assertSame(3, $stats['options']);
        $this->assertSame(2, $stats['priced']);
        $this->assertEquals(20.0, (float) $stats['average']);
        $this->assertEquals(10.0, (float) $stats['min']);
        $this->assertEquals(30.0, (float) $stats['max']);
    }

    public function test_soft_deleted_services_and_their_options_are_not_counted(): void
    {
        $kept = PrintingService::factory()->published()->create(['base_price' => '10.00']);
        $gone = PrintingService::factory()->published()->create(['base_price' => '500.00']);
        PrintingServiceOption::factory()->count(2)->create(['printing_service_id' => $kept->id]);
        PrintingServiceOption::factory()->count(4)->create(['printing_service_id' => $gone->id]);
        $gone->delete();

        $stats = $this->stats();

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['published']);
        $this->assertSame(2, $stats['options']);
        $this->assertEquals(10.0, (float) $stats['average']);
        $this->assertEquals(10.0, (float) $stats['max']);
    }

    public function test_the_tiles_ignore_the_search_and_filters(): void
    {
        PrintingService::factory()->published()->create(['name' => 'Alpha Cards', 'category' => 'Cards']);
        PrintingService::factory()->create(['name' => 'Beta Banners', 'category' => 'Banners', 'is_published' => false]);

        $all = $this->stats();
        $filtered = $this->stats(['search' => 'Alpha', 'category' => 'Cards', 'status' => 'published']);

        $this->assertSame($all, $filtered);
        $this->assertSame(2, $filtered['total']);
    }

    public function test_the_tiles_add_a_fixed_small_number_of_queries(): void
    {
        PrintingService::factory()->published()->count(5)->create()->each(
            fn ($service) => PrintingServiceOption::factory()->count(3)->create(['printing_service_id' => $service->id])
        );
        $owner = User::factory()->owner()->create();

        DB::enableQueryLog();
        $this->actingAs($owner)->get('/owner/services')->assertOk();
        $statsQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'COUNT(*) as total'));

        $this->assertCount(1, $statsQueries);
    }

    public function test_the_stat_tiles_render_on_the_list_page(): void
    {
        PrintingService::factory()->published()->create(['base_price' => '12.50']);

        $this->actingAs(User::factory()->owner()->create())->get('/owner/services')
            ->assertSee('Catalog totals')
            ->assertSee('Average base rate')
            ->assertSee('₱12.50');
    }

    public function test_the_service_form_has_a_live_preview_with_placeholders(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/owner/services/create')
            ->assertOk()
            ->assertSee('Customer view preview')
            ->assertSee('Service name');
    }
}
