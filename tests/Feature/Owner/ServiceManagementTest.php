<?php

namespace Tests\Feature\Owner;

use App\Enums\OptionGroup;
use App\Enums\PricingType;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Document Printing',
            'slug' => '',
            'category' => 'Documents',
            'description' => 'Printing for documents.',
            'pricing_type' => 'per_page',
            'base_price' => '12.50',
            'allowed_file_types' => ['pdf', 'docx'],
            'max_file_size_mb' => 2,
            'instructions' => 'Check the page order.',
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
            'index' => ['get', '/owner/services'],
            'create' => ['get', '/owner/services/create'],
            'store' => ['post', '/owner/services'],
            'edit' => ['get', '/owner/services/1/edit'],
            'update' => ['put', '/owner/services/1'],
            'destroy' => ['delete', '/owner/services/1'],
            'publish' => ['post', '/owner/services/1/publish'],
            'option store' => ['post', '/owner/services/1/options'],
            'option update' => ['put', '/owner/services/1/options/1'],
            'option destroy' => ['delete', '/owner/services/1/options/1'],
        ];
    }

    /** @dataProvider routes */
    #[DataProvider('routes')]
    public function test_guests_are_sent_to_login(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }

    #[DataProvider('routes')]
    public function test_customers_get_403(string $method, string $url): void
    {
        $this->actingAs(User::factory()->create())->{$method}($url)->assertForbidden();
    }

    // ---- creating and editing -------------------------------------------

    public function test_owner_can_create_a_service_and_money_stays_exact(): void
    {
        $this->actingAs($this->owner())
            ->post('/owner/services', $this->validData())
            ->assertRedirect();

        $service = PrintingService::firstOrFail();
        $this->assertSame('document-printing', $service->slug);
        $this->assertSame('12.50', $service->base_price);
        $this->assertSame(PricingType::PerPage, $service->pricing_type);
        $this->assertSame(['pdf', 'docx'], $service->allowed_file_types);
        $this->assertTrue($service->is_published);
        $this->assertSame('12.50', $service->fresh()->base_price);
    }

    public function test_quotation_services_do_not_need_a_base_price(): void
    {
        $this->actingAs($this->owner())
            ->post('/owner/services', $this->validData(['pricing_type' => 'quotation', 'base_price' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(PrintingService::firstOrFail()->base_price);
    }

    public function test_pricing_types_per_copy_and_per_page_need_a_base_price(): void
    {
        foreach (['per_page', 'per_copy'] as $type) {
            $this->actingAs($this->owner())
                ->post('/owner/services', $this->validData(['pricing_type' => $type, 'base_price' => '']))
                ->assertSessionHasErrors('base_price');
        }
    }

    public function test_validation_rejects_bad_input(): void
    {
        $owner = $this->owner();

        $bad = [
            'name' => ['name' => ''],
            'negative price' => ['base_price' => '-1'],
            'three decimals' => ['base_price' => '1.234'],
            'not a number' => ['base_price' => 'abc'],
            'pricing type' => ['pricing_type' => 'free'],
            'file type' => ['allowed_file_types' => ['pdf', 'exe']],
            'duplicate file type' => ['allowed_file_types' => ['pdf', 'pdf']],
            'no file types' => ['allowed_file_types' => []],
            'file types not an array' => ['allowed_file_types' => 'pdf'],
            'size zero' => ['max_file_size_mb' => 0],
            'size too big' => ['max_file_size_mb' => 11],
            'bad slug' => ['slug' => 'Not A Slug!'],
        ];

        foreach ($bad as $case => $override) {
            $key = array_key_first($override);
            $this->actingAs($owner)->post('/owner/services', $this->validData($override))->assertSessionHasErrors();

            // Array fields report their errors per item (allowed_file_types.1), so match on the prefix
            $this->assertTrue(
                collect(session('errors')->keys())->contains(fn ($errorKey) => str_starts_with($errorKey, $key)),
                "case: {$case}"
            );
        }

        $this->assertSame(0, PrintingService::count());
    }

    public function test_size_limits_are_accepted_at_the_edges(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/owner/services', $this->validData(['name' => 'One', 'max_file_size_mb' => 1]))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/owner/services', $this->validData(['name' => 'Ten', 'max_file_size_mb' => 10]))->assertSessionHasNoErrors();
    }

    public function test_a_service_can_never_promise_a_bigger_file_than_the_printing_wizard_accepts(): void
    {
        $owner = $this->owner();
        $limit = config('printing.max_file_size_mb');

        $this->actingAs($owner)->post('/owner/services', $this->validData(['name' => 'Over', 'max_file_size_mb' => $limit + 1]))->assertSessionHasErrors('max_file_size_mb');
        $this->actingAs($owner)->post('/owner/services', $this->validData(['name' => 'Exact', 'max_file_size_mb' => $limit]))->assertSessionHasNoErrors();
        $this->assertSame([$limit], PrintingService::pluck('max_file_size_mb')->all());

        // The form tells the owner the same limit
        $this->actingAs($owner)->get('/owner/services/create')->assertSee('max="'.$limit.'"', false);
    }

    public function test_old_input_and_errors_are_shown_again(): void
    {
        $this->actingAs($this->owner())
            ->from('/owner/services/create')
            ->post('/owner/services', $this->validData(['name' => 'Kept Name', 'base_price' => '-5']))
            ->assertRedirect('/owner/services/create');

        $this->followingRedirects()->get('/owner/services/create')->assertSee('Kept Name');
    }

    public function test_slugs_are_unique_and_follow_the_name(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/owner/services', $this->validData());
        $this->actingAs($owner)->post('/owner/services', $this->validData());

        $this->assertSame(['document-printing', 'document-printing-2'], PrintingService::orderBy('id')->pluck('slug')->all());
    }

    public function test_the_auto_filled_slug_gets_a_number_when_the_name_is_already_used(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/owner/services', $this->validData(['slug' => 'document-printing']));

        // The form fills "document-printing" from the name again; that must not fail as "already taken"
        $this->actingAs($owner)->post('/owner/services', $this->validData(['slug' => 'document-printing']))->assertSessionHasNoErrors();

        $this->assertSame(['document-printing', 'document-printing-2'], PrintingService::orderBy('id')->pluck('slug')->all());
    }

    public function test_file_types_are_stored_as_a_plain_list(): void
    {
        $this->actingAs($this->owner())
            ->post('/owner/services', $this->validData(['allowed_file_types' => [5 => 'pdf', 9 => 'jpg']]))
            ->assertSessionHasNoErrors();

        $this->assertSame('["pdf","jpg"]', DB::table('printing_services')->value('allowed_file_types'));
    }

    public function test_too_many_file_types_are_rejected(): void
    {
        $this->actingAs($this->owner())
            ->post('/owner/services', $this->validData(['allowed_file_types' => ['pdf', 'jpg', 'png', 'docx', 'pptx', 'xlsx', 'pdf']]))
            ->assertSessionHasErrors();
    }

    public function test_array_query_values_do_not_break_the_list(): void
    {
        $this->actingAs($this->owner())->get('/owner/services?search[]=x&category[]=y&status[]=z')->assertOk();
    }

    public function test_a_failed_option_form_does_not_change_what_the_service_form_shows(): void
    {
        $owner = $this->owner();
        $service = PrintingService::factory()->published()->create(['allowed_file_types' => ['pdf', 'png']]);

        $this->actingAs($owner)
            ->from("/owner/services/{$service->id}/edit")
            ->post("/owner/services/{$service->id}/options", ['group' => 'sides', 'label' => ''])
            ->assertRedirect("/owner/services/{$service->id}/edit");

        $html = $this->get("/owner/services/{$service->id}/edit")->getContent();

        // Published and the two file types must still be ticked, so saving the service form again changes nothing
        $this->assertMatchesRegularExpression('/<input[^>]*name="is_published"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="allowed_file_types\[\]"[^>]*value="pdf"[^>]*checked|<input[^>]*value="pdf"[^>]*name="allowed_file_types\[\]"[^>]*checked/', $html);
    }

    public function test_a_duplicate_slug_typed_by_hand_is_rejected(): void
    {
        $owner = $this->owner();
        PrintingService::factory()->create(['slug' => 'taken']);

        $this->actingAs($owner)->post('/owner/services', $this->validData(['slug' => 'taken']))->assertSessionHasErrors('slug');
    }

    public function test_the_slug_stays_the_same_when_the_name_changes(): void
    {
        $owner = $this->owner();
        $service = PrintingService::factory()->create(['name' => 'Old', 'slug' => 'old-slug']);

        $this->actingAs($owner)->put("/owner/services/{$service->id}", $this->validData(['name' => 'New Name', 'slug' => '']))->assertSessionHasNoErrors();
        $this->assertSame('old-slug', $service->fresh()->slug);
        $this->assertSame('New Name', $service->fresh()->name);

        $this->actingAs($owner)->put("/owner/services/{$service->id}", $this->validData(['name' => 'New Name', 'slug' => 'chosen-on-purpose']));
        $this->assertSame('chosen-on-purpose', $service->fresh()->slug);
    }

    public function test_a_service_can_keep_its_own_slug_when_saved(): void
    {
        $service = PrintingService::factory()->create(['slug' => 'same']);

        $this->actingAs($this->owner())
            ->put("/owner/services/{$service->id}", $this->validData(['slug' => 'same']))
            ->assertSessionHasNoErrors();
    }

    public function test_the_edit_page_shows_the_service(): void
    {
        $service = PrintingService::factory()->create(['name' => 'Shown Service']);

        $this->actingAs($this->owner())->get("/owner/services/{$service->id}/edit")->assertOk()->assertSee('Shown Service');
    }

    // ---- publish and delete --------------------------------------------

    public function test_owner_can_publish_and_unpublish(): void
    {
        $service = PrintingService::factory()->create(['is_published' => false]);
        $owner = $this->owner();

        $this->actingAs($owner)->post("/owner/services/{$service->id}/publish")->assertSessionHas('status');
        $this->assertTrue($service->fresh()->is_published);

        $this->actingAs($owner)->post("/owner/services/{$service->id}/publish");
        $this->assertFalse($service->fresh()->is_published);
    }

    public function test_delete_is_a_soft_delete_and_the_slug_stays_reserved(): void
    {
        $owner = $this->owner();
        $service = PrintingService::factory()->create(['name' => 'Document Printing', 'slug' => 'document-printing']);

        $this->actingAs($owner)->delete("/owner/services/{$service->id}")->assertRedirect('/owner/services');

        $this->assertSoftDeleted($service);
        $this->actingAs($owner)->get("/owner/services/{$service->id}/edit")->assertNotFound();

        // The unique index still covers the deleted row, so a new service gets the next free address instead of failing
        $this->actingAs($owner)->post('/owner/services', $this->validData())->assertSessionHasNoErrors();
        $this->assertSame('document-printing-2', PrintingService::latest('id')->first()->slug);
    }

    public function test_the_published_scope_returns_only_published_services(): void
    {
        PrintingService::factory()->published()->create(['name' => 'Visible']);
        PrintingService::factory()->create(['name' => 'Hidden']);
        PrintingService::factory()->published()->create(['name' => 'Removed'])->delete();

        $this->assertSame(['Visible'], PrintingService::published()->pluck('name')->all());
    }

    // ---- list -----------------------------------------------------------

    public function test_the_list_searches_filters_and_paginates(): void
    {
        $owner = $this->owner();
        PrintingService::factory()->published()->create(['name' => 'Alpha Cards', 'category' => 'Cards']);
        PrintingService::factory()->create(['name' => 'Beta Banners', 'category' => 'Large format']);
        PrintingService::factory()->count(11)->create(['category' => 'Bulk']);

        $this->actingAs($owner)->get('/owner/services?search=alpha')->assertSee('Alpha Cards')->assertDontSee('Beta Banners');
        $this->actingAs($owner)->get('/owner/services?category=Large+format')->assertSee('Beta Banners')->assertDontSee('Alpha Cards');
        $this->actingAs($owner)->get('/owner/services?status=published')->assertSee('Alpha Cards')->assertDontSee('Beta Banners');
        $this->actingAs($owner)->get('/owner/services?status=draft')->assertSee('Beta Banners')->assertDontSee('Alpha Cards');
        $this->actingAs($owner)->get('/owner/services?search=nothing-like-this')->assertSee('No services found');
        $this->actingAs($owner)->get('/owner/services')->assertSee('Page 1 of 2');
        $this->actingAs($owner)->get('/owner/services?page=2')->assertSee('Page 2 of 2');
    }

    public function test_the_empty_list_shows_the_empty_state(): void
    {
        $this->actingAs($this->owner())->get('/owner/services')->assertSee('No services yet');
    }

    public function test_the_list_does_not_run_a_query_per_row(): void
    {
        $owner = $this->owner();
        PrintingService::factory()->count(10)->create()->each(fn ($service) => PrintingServiceOption::factory()->count(2)->create(['printing_service_id' => $service->id]));

        $this->actingAs($owner);
        DB::enableQueryLog();
        $this->get('/owner/services')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'printing_service'));

        // count, page rows, categories: a fixed handful no matter how many services there are
        $this->assertLessThanOrEqual(4, $queries->count());
    }

    public function test_the_sidebar_marks_services_as_current(): void
    {
        $service = PrintingService::factory()->create();
        $owner = $this->owner();

        foreach (['/owner/services', '/owner/services/create', "/owner/services/{$service->id}/edit"] as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>\s*<span[^>]*>print<\/span>\s*Services/', $html, $url);
        }
    }

    // ---- options --------------------------------------------------------

    public function test_options_can_be_added_edited_and_removed(): void
    {
        $owner = $this->owner();
        $service = PrintingService::factory()->create();

        $this->actingAs($owner)->post("/owner/services/{$service->id}/options", [
            'group' => 'paper_size', 'label' => 'A4', 'price_modifier' => '2.50', 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $option = $service->options()->firstOrFail();
        $this->assertSame(OptionGroup::PaperSize, $option->group);
        $this->assertSame('2.50', $option->price_modifier);
        $this->assertTrue($option->is_active);

        $this->actingAs($owner)->put("/owner/services/{$service->id}/options/{$option->id}", [
            'group' => 'finish', 'label' => 'Matte', 'price_modifier' => '', // inactive: checkbox not sent
        ])->assertSessionHasNoErrors();

        $option->refresh();
        $this->assertSame(OptionGroup::Finish, $option->group);
        $this->assertSame('Matte', $option->label);
        $this->assertSame('0.00', $option->price_modifier);
        $this->assertFalse($option->is_active);

        $this->actingAs($owner)->delete("/owner/services/{$service->id}/options/{$option->id}")->assertRedirect();
        $this->assertSame(0, PrintingServiceOption::count());
    }

    public function test_option_validation(): void
    {
        $owner = $this->owner();
        $service = PrintingService::factory()->create();

        foreach ([
            ['group' => 'colour', 'label' => 'X'],
            ['group' => 'sides', 'label' => ''],
            ['group' => 'sides', 'label' => 'X', 'price_modifier' => '-1'],
            ['group' => 'sides', 'label' => 'X', 'price_modifier' => '1.999'],
        ] as $data) {
            $this->actingAs($owner)->post("/owner/services/{$service->id}/options", $data)->assertSessionHasErrorsIn('new_option');
        }

        $this->assertSame(0, PrintingServiceOption::count());
    }

    public function test_an_option_cannot_be_reached_through_another_service(): void
    {
        $owner = $this->owner();
        $mine = PrintingService::factory()->create();
        $other = PrintingService::factory()->create();
        $option = PrintingServiceOption::factory()->create(['printing_service_id' => $other->id, 'label' => 'Untouched']);

        $this->actingAs($owner)->put("/owner/services/{$mine->id}/options/{$option->id}", ['group' => 'sides', 'label' => 'Hacked'])->assertNotFound();
        $this->actingAs($owner)->delete("/owner/services/{$mine->id}/options/{$option->id}")->assertNotFound();

        $this->assertSame('Untouched', $option->fresh()->label);
    }

    public function test_the_service_of_an_option_cannot_be_changed_from_the_request(): void
    {
        $owner = $this->owner();
        $mine = PrintingService::factory()->create();
        $other = PrintingService::factory()->create();

        $this->actingAs($owner)->post("/owner/services/{$mine->id}/options", [
            'group' => 'sides', 'label' => 'Duplex', 'printing_service_id' => $other->id,
        ]);

        $option = PrintingServiceOption::firstOrFail();
        $this->assertSame($mine->id, $option->printing_service_id);

        $this->actingAs($owner)->put("/owner/services/{$mine->id}/options/{$option->id}", [
            'group' => 'sides', 'label' => 'Duplex', 'printing_service_id' => $other->id,
        ]);
        $this->assertSame($mine->id, $option->fresh()->printing_service_id);
    }

    public function test_options_of_a_deleted_service_are_not_reachable(): void
    {
        $service = PrintingService::factory()->create();
        $option = PrintingServiceOption::factory()->create(['printing_service_id' => $service->id]);
        $service->delete();

        $this->actingAs($this->owner())->put("/owner/services/{$service->id}/options/{$option->id}", ['group' => 'sides', 'label' => 'X'])->assertNotFound();
    }

    public function test_the_active_scope_returns_only_active_options(): void
    {
        $service = PrintingService::factory()->create();
        PrintingServiceOption::factory()->create(['printing_service_id' => $service->id, 'label' => 'On']);
        PrintingServiceOption::factory()->create(['printing_service_id' => $service->id, 'label' => 'Off', 'is_active' => false]);

        $this->assertSame(['On'], $service->options()->active()->pluck('label')->all());
    }

    public function test_deleting_a_service_for_good_removes_its_options(): void
    {
        $service = PrintingService::factory()->create();
        PrintingServiceOption::factory()->create(['printing_service_id' => $service->id]);

        $service->forceDelete();

        $this->assertSame(0, PrintingServiceOption::count());
    }
}
