<?php

namespace Tests\Feature\Owner;

use App\Enums\BookingStatus as B;
use App\Enums\PrintingOrderStatus as O;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\CustomerDirectoryService;
use App\Services\PaymentLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 6A: the owner's customer directory and one customer's page, every number against a hand calculation. */
class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 4 November 2026, 12:00 in Manila
        $this->travelTo(Carbon::parse('2026-11-04 04:00:00', 'UTC'));
        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Arceo']);
    }

    private function manila(string $local): Carbon
    {
        return Carbon::parse($local, 'Asia/Manila')->utc();
    }

    /** @return array{busy: User, quiet: User, free: User} */
    private function people(): array
    {
        $busy = User::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@example.test', 'phone' => '09171234567', 'created_at' => $this->manila('2026-10-31 23:30')]);
        $quiet = User::factory()->create(['name' => 'Ben Cruz', 'email' => 'ben@example.test', 'phone' => '09998887777', 'created_at' => $this->manila('2026-11-01 00:30')]);
        $free = User::factory()->create(['name' => 'Ana Reyes', 'email' => 'ana@example.test', 'phone' => null, 'created_at' => $this->manila('2026-11-02 10:00')]);

        // Maria: an accepted order awaiting 150.00, a completed and paid order, a confirmed shoot awaiting 1,200.00
        PrintingOrder::factory()->forCustomer($busy)->status(O::Accepted)->create(['confirmed_price' => '150.00', 'estimated_price' => '9.00', 'created_at' => $this->manila('2026-11-02 09:00')]);
        $paid = PrintingOrder::factory()->forCustomer($busy)->status(O::Completed)->create(['confirmed_price' => '70.00', 'created_at' => $this->manila('2026-11-01 09:00')]);
        Payment::factory()->paying($paid)->create(['amount' => '70.00', 'received_at' => $this->manila('2026-11-01 12:00')]);
        PhotographyBooking::factory()->forCustomer($busy)->status(B::Confirmed)->at($this->manila('2026-11-20 10:00'))->create(['confirmed_price' => '1200.00', 'estimated_price' => '5000.00', 'created_at' => $this->manila('2026-11-03 08:00')]);

        // Ana: a confirmed free shoot (no charge, nothing due) and a request with only an estimate
        PhotographyBooking::factory()->forCustomer($free)->status(B::Confirmed)->at($this->manila('2026-11-21 10:00'))->create(['confirmed_price' => '0.00', 'created_at' => $this->manila('2026-11-03 09:00')]);
        PrintingOrder::factory()->forCustomer($free)->status(O::Submitted)->create(['estimated_price' => '99.00', 'created_at' => $this->manila('2026-11-03 10:00')]);

        return compact('busy', 'quiet', 'free');
    }

    private function index(array $query = [])
    {
        return $this->actingAs($this->owner)->get(route('owner.customers.index', $query));
    }

    public function test_owner_only(): void
    {
        $customer = User::factory()->create();

        foreach ([route('owner.customers.index'), route('owner.customers.show', $customer)] as $url) {
            $this->actingAs($customer)->get($url)->assertForbidden();
        }
        auth()->logout();
        $this->get(route('owner.customers.index'))->assertRedirect(route('login'));
        $this->get(route('owner.customers.show', $customer))->assertRedirect(route('login'));
    }

    public function test_owners_never_appear_and_their_ids_answer_404(): void
    {
        $other = User::factory()->owner()->create(['name' => 'Second Owner', 'email' => 'second.owner@example.test']);
        User::factory()->create(['name' => 'Only Customer']);

        $this->index()->assertOk()->assertSee('Only Customer')->assertDontSee('Second Owner')->assertDontSee('second.owner@example.test')
            ->assertViewHas('tiles', fn ($t) => $t['total'] === 1);
        $this->actingAs($this->owner)->get(route('owner.customers.show', $this->owner))->assertNotFound();
        $this->actingAs($this->owner)->get(route('owner.customers.show', $other))->assertNotFound();
        $this->actingAs($this->owner)->get('/owner/customers/99999')->assertNotFound();
        $this->actingAs($this->owner)->get('/owner/customers/abc')->assertNotFound();
    }

    public function test_every_number_matches_a_hand_calculation(): void
    {
        ['busy' => $busy, 'quiet' => $quiet, 'free' => $free] = $this->people();

        $rows = app(CustomerDirectoryService::class)->customers(['search' => '', 'awaiting' => false])->getCollection()->keyBy('id');

        // Newest first
        $this->assertSame([$free->id, $quiet->id, $busy->id], $rows->keys()->all());

        $this->assertSame(2, $rows[$busy->id]->printing_orders_count);
        $this->assertSame(1, $rows[$busy->id]->photography_bookings_count);
        $this->assertSame(15000 + 120000, $rows[$busy->id]->awaiting_cents);
        $this->assertSame($this->manila('2026-11-03 08:00')->toIso8601String(), $rows[$busy->id]->last_activity_at->toIso8601String());

        $this->assertSame(0, $rows[$quiet->id]->printing_orders_count);
        $this->assertSame(0, $rows[$quiet->id]->awaiting_cents);
        $this->assertNull($rows[$quiet->id]->last_activity_at);

        // A free shoot and an estimate are never awaiting payment
        $this->assertSame(0, $rows[$free->id]->awaiting_cents);

        // The directory's amounts add up to the ledger's own awaiting total
        $this->assertSame(app(PaymentLedgerService::class)->awaiting()['cents'], $rows->sum('awaiting_cents'));

        // Tiles: Maria joined 31 October 23:30 in Manila (last month); Ben at 00:30 on 1 November (this month)
        $this->assertSame(['total' => 3, 'newThisMonth' => 2, 'open' => 2, 'awaiting' => 1], app(CustomerDirectoryService::class)->tiles());

        $this->index()->assertOk()->assertSee('₱1,350.00')->assertSee('09171234567')->assertSee('Oct 31, 2026')->assertDontSee('₱9.00');
    }

    public function test_search_is_case_insensitive_and_wildcards_are_literal(): void
    {
        $this->people();

        $names = fn (array $query) => $this->index($query)->assertOk()->viewData('customers')->getCollection()->pluck('name')->all();

        $this->assertSame(['Maria Santos'], $names(['q' => 'MARIA']));
        $this->assertSame(['Ben Cruz'], $names(['q' => 'ben@EXAMPLE']));
        $this->assertSame(['Maria Santos'], $names(['q' => '0917123']));
        $this->assertSame([], $names(['q' => 'nobody here']));

        // A typed % _ or \ is a character to find, never "match anything" (no name here contains one)
        foreach (['%', '_', '\\', '%%%%', "' OR 1=1 --"] as $wildcard) {
            $this->assertSame([], $names(['q' => $wildcard]), $wildcard);
        }
        $this->index(['q' => 'nobody here'])->assertSee('No customers found');
    }

    public function test_hostile_and_array_values_are_refused_with_a_message(): void
    {
        foreach ([['q' => ['a', 'b']], ['q' => str_repeat('a', 61)], ['q' => "ma\0ria"], ['q' => "a\x1Bb"], ['awaiting' => 'yes'], ['awaiting' => ['1']], ['page' => '-3']] as $query) {
            $this->assertTrue($this->index($query)->isRedirect(route('owner.customers.index')), json_encode($query));
            $this->assertTrue(session()->has('errors'), json_encode($query));
        }

        $this->followingRedirects()->actingAs($this->owner)->get(route('owner.customers.index', ['q' => ['x']]))
            ->assertOk()->assertSee('Search with plain text.')->assertSee('The full list is shown.');
    }

    public function test_the_awaiting_filter_and_pagination_keep_the_filters(): void
    {
        ['busy' => $busy] = $this->people();

        $this->index(['awaiting' => '1'])->assertOk()->assertViewHas('customers', fn ($page) => $page->getCollection()->pluck('id')->all() === [$busy->id]);

        User::factory()->count(25)->create(['name' => 'Paged Customer']);
        $page = $this->index(['q' => 'paged'])->assertOk()->viewData('customers');
        $this->assertSame(25, $page->total());
        $this->assertCount(20, $page->items());
        $this->assertStringContainsString('q=paged', $page->nextPageUrl());
        $this->assertCount(5, $this->index(['q' => 'paged', 'page' => 2])->viewData('customers')->items());
    }

    public function test_the_queries_are_fixed_however_many_customers_exist(): void
    {
        $count = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get($url)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        ['busy' => $busy] = $this->people();
        $few = $count(route('owner.customers.index'));
        $detail = $count(route('owner.customers.show', $busy));

        foreach (User::factory()->count(12)->create() as $customer) {
            PrintingOrder::factory()->forCustomer($customer)->status(O::Accepted)->create(['confirmed_price' => '10.00']);
            PhotographyBooking::factory()->forCustomer($customer)->status(B::Requested)->create();
        }
        foreach (range(1, 8) as $i) {
            $order = PrintingOrder::factory()->forCustomer($busy)->status(O::Completed)->create(['confirmed_price' => '5.00']);
            Payment::factory()->paying($order)->create(['amount' => '5.00']);
        }

        $this->assertSame($few, $count(route('owner.customers.index')));
        $this->assertSame($detail, $count(route('owner.customers.show', $busy)));
    }

    public function test_the_detail_shows_only_that_customer_with_owner_links(): void
    {
        ['busy' => $busy, 'free' => $free] = $this->people();
        $mine = PrintingOrder::where('customer_id', $busy->id)->orderBy('id')->get();
        $theirs = PrintingOrder::where('customer_id', $free->id)->first();
        $removed = PrintingOrder::factory()->forCustomer($busy)->status(O::Cancelled)->create(['reference_number' => 'PR-077777']);
        $removed->delete();

        $response = $this->actingAs($this->owner)->get(route('owner.customers.show', $busy))->assertOk()
            ->assertSee('Maria Santos')->assertSee('maria@example.test')->assertSee('09171234567')
            ->assertSee(route('owner.orders.show', $mine[0]))->assertSee(route('owner.orders.show', $mine[1]))
            ->assertSee('PR-077777')->assertSee('Removed')->assertDontSee(route('owner.orders.show', $removed))
            ->assertDontSee($theirs->reference_number)->assertDontSee('ana@example.test')
            ->assertDontSee(route('customer.printing.show', $mine[0]));

        $data = $response->viewData('data');
        $this->assertSame(2, $data['orders']); // two live orders; the removed one is listed but not counted
        $this->assertSame(1, $data['bookings']);
        $this->assertSame(7000, $data['recorded']['cents']);
        $this->assertSame(1, $data['recorded']['count']);
        $this->assertSame(135000, $data['awaitingCents']);
        $this->assertSame(4, $data['requests']->total());
        $this->assertSame(1, $data['payments']->total());
        $response->assertSee('₱70.00')->assertSee('₱1,350.00');
    }

    public function test_a_crafted_page_never_reaches_another_customer(): void
    {
        ['busy' => $busy, 'free' => $free] = $this->people();
        $theirs = PrintingOrder::where('customer_id', $free->id)->first();
        Payment::factory()->paying(PrintingOrder::factory()->forCustomer($free)->status(O::Completed)->create(['confirmed_price' => '33.00']))->create(['amount' => '33.00']);

        foreach ([['requests' => 2], ['requests' => 999], ['payments' => 5], ['requests' => -1, 'payments' => 'x']] as $query) {
            $response = $this->actingAs($this->owner)->get(route('owner.customers.show', ['customer' => $busy, ...$query]))->assertOk();
            $this->assertTrue($response->viewData('data')['requests']->getCollection()->every(fn ($r) => $r->customer_id === $busy->id));
            $this->assertTrue($response->viewData('data')['payments']->getCollection()->every(fn ($p) => $p->payable->customer_id === $busy->id));
            $response->assertDontSee($theirs->reference_number)->assertDontSee('₱33.00');
        }
    }

    public function test_no_secret_is_ever_shown(): void
    {
        ['busy' => $busy] = $this->people();
        $busy->forceFill(['remember_token' => 'remember-me-token-123'])->save();
        $hash = $busy->fresh()->password;

        foreach ([route('owner.customers.index'), route('owner.customers.show', $busy)] as $url) {
            $this->actingAs($this->owner)->get($url)->assertOk()->assertDontSee($hash, false)->assertDontSee('remember-me-token-123', false);
        }
    }

    public function test_hostile_names_are_escaped_and_the_nav_marks_the_page(): void
    {
        $customer = User::factory()->create(['name' => '<script>alert(1)</script>'.str_repeat('Long', 30), 'email' => 'x"><img src=x>@example.test']);

        $html = $this->index(['q' => '<script>'])->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x>', false)->getContent();
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('owner.customers.index'), '/').'"\s+aria-current="page"/', $html);
        $this->actingAs($this->owner)->get(route('owner.customers.show', $customer))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x>', false);
    }

    public function test_a_page_past_the_end_is_not_called_empty(): void
    {
        ['busy' => $busy] = $this->people();

        $this->index(['page' => 50])->assertOk()->assertSee('This page is past the end of the list.')->assertDontSee('No customers yet');
        $this->actingAs($this->owner)->get(route('owner.customers.show', ['customer' => $busy, 'requests' => 50, 'payments' => 50]))->assertOk()
            ->assertSee('This page is past the end of the list.')->assertDontSee('No requests yet')->assertDontSee('No payments recorded');
    }

    public function test_an_empty_directory(): void
    {
        $this->index()->assertOk()->assertSee('No customers yet')
            ->assertViewHas('tiles', fn ($t) => $t === ['total' => 0, 'newThisMonth' => 0, 'open' => 0, 'awaiting' => 0]);
    }
}
