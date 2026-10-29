<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BookingStatus as B;
use App\Enums\PrintingOrderStatus as O;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 5C: one list of the customer's printing orders and bookings, own rows only. */
class RequestHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-04 04:00:00', 'UTC'));
        $this->ana = User::factory()->create();
        $this->ben = User::factory()->create();
    }

    private function order(User $customer, string $sentManila, array $extra = []): PrintingOrder
    {
        $order = PrintingOrder::factory()->forCustomer($customer)->create($extra);
        $order->forceFill(['created_at' => Carbon::parse($sentManila, 'Asia/Manila')->utc()])->save();

        return $order;
    }

    private function booking(User $customer, string $sentManila, array $extra = []): PhotographyBooking
    {
        $booking = PhotographyBooking::factory()->forCustomer($customer)->create($extra);
        $booking->forceFill(['created_at' => Carbon::parse($sentManila, 'Asia/Manila')->utc()])->save();

        return $booking;
    }

    private function list(array $query = [])
    {
        return $this->actingAs($this->ana)->get(route('customer.requests.index', $query));
    }

    private function refs($response): array
    {
        return $response->viewData('requests')->getCollection()->pluck('reference_number')->all();
    }

    public function test_both_kinds_merge_newest_first_and_paginate_with_correct_totals(): void
    {
        $expected = [];
        foreach (range(1, 10) as $day) {
            $expected[] = $this->order($this->ana, "2026-10-{$day} 09:00")->reference_number;
            $expected[] = $this->booking($this->ana, "2026-10-{$day} 10:00")->reference_number;
        }
        $this->order($this->ben, '2026-10-30 09:00');
        $expected = array_reverse($expected);

        $first = $this->list()->assertOk();
        $this->assertSame(array_slice($expected, 0, 15), $this->refs($first));
        $this->assertSame(20, $first->viewData('requests')->total());
        $this->assertSame(2, $first->viewData('requests')->lastPage());
        $first->assertSee('Showing 1 to 15 of 20');

        $this->assertSame(array_slice($expected, 15), $this->refs($this->list(['page' => 2])));
        $this->assertSame([], $this->refs($this->list(['page' => 9])));
        $this->assertSame(['all' => 20, 'printing' => 10, 'photography' => 10], $first->viewData('counts'));
    }

    public function test_kind_status_search_and_dates_filter_and_survive_the_pages(): void
    {
        $service = PrintingService::factory()->published()->create(['name' => 'Thesis Hardbinding']);
        $package = PhotographyPackage::factory()->published()->create(['name' => 'Family Portrait']);
        foreach (range(1, 17) as $i) {
            $this->order($this->ana, '2026-11-01 10:00', ['printing_service_id' => $service->id, 'status' => O::Submitted]);
        }
        $accepted = $this->order($this->ana, '2026-11-02 10:00', ['status' => O::Accepted]);
        $booking = $this->booking($this->ana, '2026-11-03 10:00', ['photography_package_id' => $package->id, 'status' => B::Proposed]);

        $printing = $this->list(['kind' => 'printing', 'status' => 'submitted']);
        $this->assertSame(17, $printing->viewData('requests')->total());
        $printing->assertSee('kind=printing&amp;status=submitted&amp;page=2', false);
        $this->assertSame(2, $this->list(['kind' => 'printing', 'status' => 'submitted', 'page' => 2])->viewData('requests')->count());

        $this->assertSame([$booking->reference_number], $this->refs($this->list(['kind' => 'photography'])));
        $this->assertSame([$booking->reference_number], $this->refs($this->list(['kind' => 'photography', 'status' => 'proposed'])));
        $this->assertSame([$accepted->reference_number], $this->refs($this->list(['q' => $accepted->reference_number])));
        $this->assertSame([$booking->reference_number], $this->refs($this->list(['q' => 'family port'])));
        $this->assertSame(17, $this->list(['q' => 'Hardbinding'])->viewData('requests')->total());

        // The shop's calendar day: 2 November in Manila holds only the accepted order
        $this->assertSame([$accepted->reference_number], $this->refs($this->list(['from' => '2026-11-02', 'to' => '2026-11-02'])));
    }

    public function test_unknown_values_fall_back_with_a_message_and_wildcards_are_literal(): void
    {
        $this->order($this->ana, '2026-11-01 10:00');

        foreach ([['kind' => 'invoices'], ['kind' => 'printing', 'status' => 'proposed'], ['status' => 'submitted'], ['kind' => ['printing']],
            ['from' => '2026-02-30'], ['from' => '2026-11-05', 'to' => '2026-11-01'], ['q' => str_repeat('x', 61)], ['q' => ['x']], ['page' => 'abc']] as $query) {
            $this->list($query)->assertRedirect(route('customer.requests.index'))->assertSessionHasErrors();
        }

        foreach (['%', '_', '\\', "'", '"', "PR' OR 1=1 --"] as $hostile) {
            $this->assertSame([], $this->refs($this->list(['q' => $hostile])->assertOk()));
        }
    }

    public function test_another_customers_rows_never_appear(): void
    {
        $theirs = $this->order($this->ben, '2026-11-01 10:00');
        $theirBooking = $this->booking($this->ben, '2026-11-01 11:00');
        $this->order($this->ana, '2026-11-01 12:00');

        foreach ([[], ['q' => $theirs->reference_number], ['q' => $theirBooking->reference_number], ['kind' => 'photography'], ['page' => 2]] as $query) {
            // The rows listed (a search box shows back what was typed, so the page text is not the test)
            $refs = $this->refs($this->list($query)->assertOk());
            $this->assertNotContains($theirs->reference_number, $refs);
            $this->assertNotContains($theirBooking->reference_number, $refs);
        }

        $this->list()->assertDontSee($theirs->reference_number)->assertDontSee($theirBooking->reference_number);
        $this->actingAs(User::factory()->owner()->create())->get(route('customer.requests.index'))->assertForbidden();
        auth()->logout();
        $this->get(route('customer.requests.index'))->assertRedirect(route('login'));
    }

    public function test_long_and_hostile_names_are_escaped_and_cut(): void
    {
        $service = PrintingService::factory()->published()->create(['name' => '<script>alert(1)</script>'.str_repeat('Very long service name ', 12)]);
        $this->order($this->ana, '2026-11-01 10:00', ['printing_service_id' => $service->id, 'snapshot' => ['service' => ['name' => $service->name], 'options' => []]]);

        $this->list()->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false)->assertSee('truncate', false);
    }

    public function test_the_queries_are_fixed_however_many_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->list()->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->order($this->ana, '2026-11-01 10:00');
        $this->booking($this->ana, '2026-11-01 11:00');
        $few = $count();
        foreach (range(1, 12) as $i) {
            $this->order($this->ana, '2026-10-20 10:00');
            $this->booking($this->ana, '2026-10-21 10:00');
        }

        $this->assertSame($few, $count());
    }

    public function test_an_empty_history(): void
    {
        $this->list()->assertOk()->assertSee('No requests yet');
        $this->list(['q' => 'nothing'])->assertSee('No requests found');
    }
}
