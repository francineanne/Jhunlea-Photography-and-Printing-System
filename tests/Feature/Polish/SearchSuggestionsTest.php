<?php

namespace Tests\Feature\Polish;

use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Phase 10F: the suggestions under every search box (<x-ui.typeahead>) and who may see which. */
class SearchSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private PrintingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Owner']);
        $this->service = PrintingService::factory()->published()->create(['name' => 'Document Printing']);
    }

    private function customer(string $name, array $extra = []): User
    {
        return User::factory()->create(['name' => $name] + $extra);
    }

    /** @return list<string> */
    private function labels(string $url, ?User $as = null): array
    {
        $request = $as ? $this->actingAs($as) : $this;

        return collect($request->getJson($url)->assertOk()->json('items'))->pluck('label')->all();
    }

    public function test_one_letter_matches_the_start_of_a_word_only(): void
    {
        $this->customer('Ana Reyes');
        $this->customer('Maria Ana Cruz');
        $this->customer('Santos Bay');

        $labels = $this->labels('/owner/suggest?scope=customers&q=a', $this->owner);

        $this->assertEqualsCanonicalizing(['Ana Reyes', 'Maria Ana Cruz'], $labels);
        // The name that STARTS with the letter comes first
        $this->assertSame('Ana Reyes', $labels[0]);
    }

    public function test_two_letters_match_anywhere(): void
    {
        $this->customer('Santos Bay');
        $this->customer('Ana Reyes');

        $this->assertSame(['Santos Bay'], $this->labels('/owner/suggest?scope=customers&q=nt', $this->owner));
    }

    public function test_percent_and_underscore_are_literal(): void
    {
        $this->customer('Ana Reyes');
        $this->customer('Ben 100%_ Shop');

        $this->assertSame([], $this->labels('/owner/suggest?scope=customers&q='.urlencode('%'), $this->owner));
        $this->assertSame([], $this->labels('/owner/suggest?scope=customers&q='.urlencode('a_'), $this->owner));
        $this->assertSame([], $this->labels('/owner/suggest?scope=customers&q='.urlencode('_'), $this->owner));
        // (On MySQL a backslash is the LIKE escape, so "%_" then finds "Ben 100%_ Shop"; SQLite has no default escape,
        // so here the point proved is the one that matters: a wildcard never matches everything.)
    }

    public function test_at_most_eight_items(): void
    {
        foreach (range(1, 12) as $n) {
            $this->customer('Carla Number '.$n);
        }

        $this->assertCount(8, $this->labels('/owner/suggest?scope=customers&q=carla', $this->owner));
    }

    public function test_the_message_scope_lists_customers_with_an_account_only_and_posts_to_start(): void
    {
        $ana = $this->customer('Ana Reyes');
        User::factory()->walkIn()->create(['name' => 'Ana Walkin']);

        $items = $this->actingAs($this->owner)->getJson('/owner/suggest?scope=message&q=ana')->assertOk()->json('items');

        $this->assertSame(['Ana Reyes'], array_column($items, 'label'));
        $this->assertSame(route('owner.messages.start'), $items[0]['post']['url']);
        $this->assertSame($ana->id, $items[0]['post']['fields']['customer']);
        // Only the fields the dropdown shows: no email
        $this->assertSame(['label', 'sub', 'type', 'url', 'post'], array_keys($items[0]));
        $this->assertStringNotContainsString((string) $ana->email, json_encode($items));
    }

    public function test_orders_and_payments_find_a_reference_with_or_without_the_hyphen(): void
    {
        $ana = $this->customer('Ana Reyes');
        $order = PrintingOrder::factory()->forCustomer($ana)->create(['reference_number' => 'PR-004321', 'printing_service_id' => $this->service->id]);

        $items = $this->actingAs($this->owner)->getJson('/owner/suggest?scope=payments&q=pr004321')->assertOk()->json('items');
        $this->assertSame('PR-004321 · Ana Reyes', $items[0]['label']);
        $this->assertSame(route('owner.payments.index', ['pay' => 'printing_order:'.$order->id]), $items[0]['url']);

        $items = $this->actingAs($this->owner)->getJson('/owner/suggest?scope=orders&q=PR-0043')->assertOk()->json('items');
        $this->assertSame(route('owner.orders.show', $order), $items[0]['url']);
        // Orders scope never lists bookings
        PhotographyBooking::factory()->forCustomer($ana)->create(['reference_number' => 'PB-004321']);
        $this->assertSame(['Order'], array_unique(array_column($this->actingAs($this->owner)->getJson('/owner/suggest?scope=orders&q=ana')->json('items'), 'type')));
    }

    public function test_a_customer_only_sees_their_own_requests(): void
    {
        $ana = $this->customer('Ana Reyes');
        $ben = $this->customer('Ben Cruz');
        PrintingOrder::factory()->forCustomer($ana)->create(['reference_number' => 'PR-000111', 'printing_service_id' => $this->service->id]);
        PrintingOrder::factory()->forCustomer($ben)->create(['reference_number' => 'PR-000112', 'printing_service_id' => $this->service->id]);

        $this->assertSame(['PR-000111'], $this->labels('/my/suggest?q=PR-0001', $ana));
        $this->assertSame(['PR-000112'], $this->labels('/my/suggest?q=document', $ben));
    }

    public function test_roles_are_kept_apart(): void
    {
        $ana = $this->customer('Ana Reyes');

        $this->getJson('/owner/suggest?scope=customers&q=a')->assertUnauthorized();
        $this->actingAs($ana)->getJson('/owner/suggest?scope=customers&q=a')->assertForbidden();
        $this->actingAs($this->owner)->getJson('/my/suggest?q=a')->assertForbidden();
        // A wrong scope is simply nothing to suggest
        $this->assertSame([], $this->labels('/owner/suggest?scope=nope&q=a', $this->owner));
    }

    public function test_the_public_endpoint_lists_published_items_only(): void
    {
        PrintingService::factory()->create(['name' => 'Draft Banner', 'is_published' => false]);
        PrintingService::factory()->published()->create(['name' => 'Banner Printing']);
        PhotographyPackage::factory()->published()->create(['name' => 'Baby Portrait']);
        PhotographyPackage::factory()->create(['name' => 'Baby Hidden', 'is_published' => false]);

        $this->assertSame(['Banner Printing'], $this->labels('/suggest?scope=services&q=ban'));
        $this->assertSame(['Baby Portrait'], $this->labels('/suggest?scope=packages&q=baby'));
        $this->customer('Ana Reyes');
        $this->assertSame([], $this->labels('/suggest?scope=customers&q=a'));
    }

    public function test_an_empty_term_returns_nothing(): void
    {
        $this->customer('Ana Reyes');

        $this->assertSame([], $this->labels('/owner/suggest?scope=customers&q=%20', $this->owner));
    }

    public function test_the_endpoint_has_its_own_throttle(): void
    {
        RateLimiter::clear('public-suggest');
        foreach (range(1, 60) as $i) {
            $this->getJson('/suggest?scope=services&q=d')->assertOk();
        }

        $this->getJson('/suggest?scope=services&q=d')->assertTooManyRequests();
    }

    public function test_the_search_forms_still_work_without_javascript(): void
    {
        $this->customer('Ana Reyes');
        $this->customer('Ben Cruz');

        // The box is a plain GET field named q inside the page's own form
        $this->actingAs($this->owner)->get('/owner/customers?q=ana')->assertOk()
            ->assertSee('data-typeahead', false)->assertSee('name="q"', false)->assertSee('Ana Reyes')->assertDontSee('Ben Cruz');
        $this->get('/services?q=document')->assertOk()->assertSee('id="service-search"', false)->assertSee('Document Printing');
    }
}
