<?php

namespace Tests\Feature\Public;

use App\Enums\OptionGroup;
use App\Enums\PricingType;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_service_shows_its_details_price_text_and_active_options_only(): void
    {
        $service = PrintingService::factory()->published()->create([
            'name' => 'Document Printing', 'category' => 'Documents', 'pricing_type' => PricingType::PerPage,
            'base_price' => '3.00', 'description' => 'Sharp black and white pages.', 'instructions' => 'Send a PDF.',
            'allowed_file_types' => ['pdf'], 'max_file_size_mb' => 20,
        ]);
        PrintingServiceOption::factory()->create(['printing_service_id' => $service->id, 'group' => OptionGroup::PaperSize, 'label' => 'A4', 'is_active' => true]);
        PrintingServiceOption::factory()->create(['printing_service_id' => $service->id, 'group' => OptionGroup::PaperSize, 'label' => 'Secret Size', 'is_active' => false]);

        $this->get('/services')
            ->assertOk()
            ->assertSee('Document Printing')
            ->assertSee('Sharp black and white pages.')
            ->assertSee('Send a PDF.')
            ->assertSee('From ₱3.00 per page')
            ->assertSee('A4')
            ->assertSee('up to 20 MB')
            ->assertDontSee('Secret Size');
    }

    public function test_the_search_and_category_narrow_the_list_and_keep_each_other(): void
    {
        PrintingService::factory()->published()->create(['name' => 'Poster Print', 'category' => 'Large format']);
        PrintingService::factory()->published()->create(['name' => 'Photo Print', 'category' => 'Photos']);

        $this->assertSame(['Photo Print'], $this->get('/services?q=photo')->viewData('services')->pluck('name')->all());
        $this->assertSame(['Poster Print'], $this->get('/services?category=Large+format')->viewData('services')->pluck('name')->all());

        $page = $this->get('/services?q=print&category=Photos')->assertOk();
        $this->assertSame(['Photo Print'], $page->viewData('services')->pluck('name')->all());
        $page->assertSee(e(route('services.index', ['category' => 'Large format', 'q' => 'print'])), false);
    }

    public function test_a_search_with_no_result_says_so_and_offers_a_way_back(): void
    {
        PrintingService::factory()->published()->create();

        $this->get('/services?q=zzzz')->assertOk()->assertSee('Nothing matches your search')->assertSee(route('services.index'), false);
    }

    public function test_an_empty_catalog_shows_a_friendly_message(): void
    {
        $this->get('/services')->assertOk()->assertSee('No printing services are listed yet');
    }

    public function test_it_has_a_title_description_and_canonical_without_the_query(): void
    {
        PrintingService::factory()->published()->create();

        $page = $this->get('/services?q=a')->assertOk();

        $page->assertSee('<title>Printing services | '.e(config('app.name')).'</title>', false);
        $page->assertSee('<meta name="description"', false);
        $page->assertSee('<link rel="canonical" href="'.url('/services').'">', false);
        $page->assertSee('og:title', false);
    }

    public function test_it_shows_the_face_to_face_notice_and_no_internal_fields(): void
    {
        PrintingService::factory()->published()->create(['slug' => 'internal-slug-value']);

        $this->get('/services')->assertSee('Payment is made face-to-face at Jhunlea Photography &amp; Printing.', false)
            ->assertDontSee('is_published')->assertDontSee('internal_notes')->assertDontSee('sort_order');
    }
}
