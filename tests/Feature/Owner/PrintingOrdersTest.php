<?php

namespace Tests\Feature\Owner;

use App\Enums\OrderEvent;
use App\Enums\PrintingOrderStatus as Status;
use App\Models\OrderStatusHistory;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrintingOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['name' => 'Shop Owner']);
        $this->customer = User::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@example.test', 'phone' => '0917 000 0000']);
    }

    private function order(Status $status = Status::Submitted, array $attributes = [], ?User $customer = null): PrintingOrder
    {
        return PrintingOrder::factory()->forCustomer($customer ?? $this->customer)->status($status)->create($attributes);
    }

    private function as(): static
    {
        return $this->actingAs($this->owner);
    }

    // Access

    public function test_a_customer_cannot_reach_any_owner_order_route(): void
    {
        $order = $this->order();
        $routes = [
            ['get', route('owner.orders.index')],
            ['get', route('owner.orders.show', $order)],
            ['get', '/owner/printing-orders/999999'],
        ];
        foreach (['review', 'accept', 'reject', 'info', 'processing', 'ready', 'complete', 'cancel', 'price'] as $action) {
            $routes[] = ['post', route("owner.orders.{$action}", $order)];
        }

        foreach ($routes as [$method, $url]) {
            $this->actingAs($this->customer)->{$method}($url, ['confirmed_price' => '1', 'reason' => 'x', 'message' => 'x'])->assertForbidden();
        }

        $this->assertSame(Status::Submitted, $order->fresh()->status);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('owner.orders.index'))->assertRedirect('/login');
        $this->post(route('owner.orders.review', $this->order()))->assertRedirect('/login');
    }

    // The list

    public function test_the_list_shows_every_order_newest_first_with_counts(): void
    {
        $this->order(Status::Submitted, ['reference_number' => 'PR-000001', 'created_at' => now()->subDays(2)]);
        $this->order(Status::Completed, ['reference_number' => 'PR-000002', 'created_at' => now()->subDay()], User::factory()->create(['name' => 'Juan Cruz']));
        $this->order(Status::Submitted, ['reference_number' => 'PR-000003']);

        $response = $this->as()->get(route('owner.orders.index'))->assertOk();

        $response->assertSeeInOrder(['PR-000003', 'PR-000002', 'PR-000001'])->assertSee('Maria Santos')->assertSee('Juan Cruz');
        $this->assertSame(2, $response->viewData('counts')['submitted']);
        $this->assertSame(1, $response->viewData('counts')['completed']);
    }

    public function test_the_list_filters_by_status_reference_name_and_dates(): void
    {
        $this->order(Status::Submitted, ['reference_number' => 'PR-000101', 'created_at' => Carbon::parse('2026-10-10 03:00', 'UTC')]);
        $this->order(Status::Accepted, ['reference_number' => 'PR-000102', 'created_at' => Carbon::parse('2026-10-20 03:00', 'UTC')], User::factory()->create(['name' => 'Juan Cruz']));

        $this->as()->get(route('owner.orders.index', ['status' => 'accepted']))->assertSee('PR-000102')->assertDontSee('PR-000101');
        $this->as()->get(route('owner.orders.index', ['q' => '000101']))->assertSee('PR-000101')->assertDontSee('PR-000102');
        $this->as()->get(route('owner.orders.index', ['q' => 'juan']))->assertSee('PR-000102')->assertDontSee('PR-000101');
        $this->as()->get(route('owner.orders.index', ['from' => '2026-10-15', 'to' => '2026-10-31']))->assertSee('PR-000102')->assertDontSee('PR-000101');
    }

    public function test_the_date_range_uses_the_shops_calendar_day(): void
    {
        // 17:00 UTC on the 14th is 1 AM on the 15th in Manila
        $this->order(attributes: ['reference_number' => 'PR-000201', 'created_at' => Carbon::parse('2026-10-14 17:00', 'UTC')]);

        $this->as()->get(route('owner.orders.index', ['from' => '2026-10-15', 'to' => '2026-10-15']))->assertSee('PR-000201');
        $this->as()->get(route('owner.orders.index', ['from' => '2026-10-14', 'to' => '2026-10-14']))->assertDontSee('PR-000201');
    }

    /** @return array<string, array{string}> */
    public static function wildcards(): array
    {
        return [
            'percent' => ['%'],
            'underscore' => ['_'],
            'backslash' => ['\\'],
            'sql' => ["' OR '1'='1"],
            'union' => ['x" UNION SELECT password FROM users --'],
        ];
    }

    #[DataProvider('wildcards')]
    public function test_search_matches_wildcards_and_quotes_literally(string $search): void
    {
        $this->order(attributes: ['reference_number' => 'PR-000301']);

        $this->as()->get(route('owner.orders.index', ['q' => $search]))
            ->assertOk()
            ->assertDontSee('PR-000301')
            ->assertSee('No orders found');
    }

    public function test_bad_filters_send_the_owner_back_to_the_full_list(): void
    {
        foreach ([['status' => 'paid'], ['from' => '2026-13-45'], ['from' => '2026-10-20', 'to' => '2026-10-01'], ['q' => str_repeat('a', 61)], ['q' => ['a']]] as $filters) {
            $this->as()->get(route('owner.orders.index', $filters))->assertRedirect(route('owner.orders.index'))->assertSessionHasErrors();
        }
    }

    // One order

    public function test_the_detail_shows_the_customer_snapshot_files_prices_and_history(): void
    {
        $order = $this->order(Status::UnderReview, [
            'notes' => 'Bind on the left.',
            'snapshot' => ['service' => ['name' => 'Thesis Binding', 'pricing_type' => 'per_copy', 'base_price' => '150.00'], 'options' => [['id' => 9, 'group' => 'finish', 'label' => 'Hard cover', 'price_modifier' => '50.00']], 'pages' => null, 'unit_price' => '200.00'],
            'estimated_price' => '400.00',
        ]);
        OrderStatusHistory::factory()->create(['printing_order_id' => $order->id, 'changed_by' => $this->customer->id]);

        $this->as()->get(route('owner.orders.show', $order))
            ->assertOk()
            ->assertSee('Maria Santos')->assertSee('maria@example.test')->assertSee('0917 000 0000')
            ->assertSee('Thesis Binding')->assertSee('Hard cover')->assertSee('+₱50.00')->assertSee('₱150.00')
            ->assertSee('Bind on the left.')
            ->assertSee('₱400.00')
            ->assertSee('Customer · Maria Santos');
    }

    public function test_only_the_actions_the_status_table_allows_are_shown(): void
    {
        $this->as()->get(route('owner.orders.show', $this->order(Status::Submitted)))
            ->assertSee(route('owner.orders.review', PrintingOrder::latest('id')->first()), false)
            ->assertDontSee('Accept with a confirmed price')->assertDontSee('Mark processing');

        $review = $this->order(Status::UnderReview);
        $this->as()->get(route('owner.orders.show', $review))
            ->assertSee('Accept with a confirmed price')->assertSee('Reject')->assertSee('Ask the customer for more information')
            ->assertDontSee('Start review')->assertDontSee('Change the confirmed price');

        $this->as()->get(route('owner.orders.show', $this->order(Status::Accepted, ['confirmed_price' => '10.00'])))
            ->assertSee('Mark processing')->assertSee('Change the confirmed price')->assertSee('Cancel order')->assertDontSee('Ask the customer');

        $this->as()->get(route('owner.orders.show', $this->order(Status::Completed)))
            ->assertDontSee('Mark processing')->assertDontSee('Cancel order')->assertDontSee('Change the confirmed price');
    }

    // Actions

    public function test_the_whole_flow_from_review_to_completed(): void
    {
        $order = $this->order(Status::Submitted);

        $this->as()->post(route('owner.orders.review', $order))->assertSessionHas('status');
        $this->as()->post(route('owner.orders.info', $order), ['message' => 'Is page 2 in colour?'])->assertSessionHas('status');
        $this->assertSame('Is page 2 in colour?', $order->fresh()->info_request);
        $this->as()->post(route('owner.orders.accept', $order), ['confirmed_price' => '480.50', 'note' => 'Checked.'])->assertSessionHas('status');
        $this->assertNull($order->fresh()->info_request);
        $this->as()->post(route('owner.orders.price', $order), ['confirmed_price' => '500', 'reason' => 'Extra page.'])->assertSessionHas('status');
        $this->as()->post(route('owner.orders.processing', $order))->assertSessionHas('status');
        $this->as()->post(route('owner.orders.ready', $order))->assertSessionHas('status');
        $this->as()->post(route('owner.orders.complete', $order))->assertSessionHas('status');

        $order->refresh();
        $this->assertSame(Status::Completed, $order->status);
        $this->assertSame('500.00', $order->confirmed_price);
        $this->assertSame(
            [null, 'info_requested', null, 'price_changed', null, null, null],
            $order->statusHistories->map(fn ($row) => $row->event?->value)->all(),
        );
        $this->assertSame('Checked.', $order->statusHistories[2]->note);
    }

    public function test_accept_needs_a_valid_price(): void
    {
        $order = $this->order(Status::UnderReview);

        foreach ([[], ['confirmed_price' => ''], ['confirmed_price' => '-1'], ['confirmed_price' => '1.234'], ['confirmed_price' => '100000000'], ['confirmed_price' => 'abc']] as $data) {
            $this->as()->post(route('owner.orders.accept', $order), $data)->assertSessionHasErrors('confirmed_price');
        }

        $this->assertSame(Status::UnderReview, $order->fresh()->status);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_reject_needs_a_reason_of_up_to_1000_characters(): void
    {
        $order = $this->order(Status::UnderReview);

        $this->as()->post(route('owner.orders.reject', $order), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->as()->post(route('owner.orders.reject', $order), ['reason' => str_repeat('a', 1001)])->assertSessionHasErrors('reason');
        $this->assertSame(Status::UnderReview, $order->fresh()->status);

        $this->as()->post(route('owner.orders.reject', $order), ['reason' => 'The file is too small to print.'])->assertSessionHas('status');
        $this->assertSame('The file is too small to print.', $order->fresh()->rejection_reason);
        $this->assertSame(Status::Rejected, $order->fresh()->status);
    }

    public function test_the_info_request_is_limited_to_1000_characters(): void
    {
        $order = $this->order(Status::UnderReview);

        $this->as()->post(route('owner.orders.info', $order), ['message' => str_repeat('a', 1001)])->assertSessionHasErrors('message');
        $this->as()->post(route('owner.orders.info', $order), ['message' => ''])->assertSessionHasErrors('message');

        $this->assertNull($order->fresh()->info_request);
    }

    public function test_an_action_the_table_does_not_allow_comes_back_as_a_message(): void
    {
        $order = $this->order(Status::Submitted);

        $this->as()->post(route('owner.orders.complete', $order))
            ->assertRedirect(route('owner.orders.show', $order).'#actions')
            ->assertSessionHas('error');

        $this->as()->post(route('owner.orders.price', $order), ['confirmed_price' => '10'])->assertSessionHas('error');

        $this->assertSame(Status::Submitted, $order->fresh()->status);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_a_double_submit_writes_one_history_row(): void
    {
        $order = $this->order(Status::Submitted);

        $this->as()->post(route('owner.orders.review', $order))->assertSessionHas('status');
        $this->as()->post(route('owner.orders.review', $order))->assertSessionHas('error');
        $this->as()->post(route('owner.orders.info', $order), ['message' => 'Same.']);
        $this->as()->post(route('owner.orders.info', $order), ['message' => 'Same.']);

        $this->assertSame(1, OrderStatusHistory::where('event', null)->count());
        $this->assertSame(1, OrderStatusHistory::where('event', OrderEvent::InfoRequested)->count());
    }

    public function test_the_status_cannot_be_set_from_a_form_field(): void
    {
        $order = $this->order(Status::Submitted);

        // The target status comes from the route; extra fields are ignored
        $this->as()->post(route('owner.orders.review', $order), ['status' => 'completed', 'confirmed_price' => '1', 'customer_id' => $this->owner->id]);

        $order->refresh();
        $this->assertSame(Status::UnderReview, $order->status);
        $this->assertNull($order->confirmed_price);
        $this->assertSame($this->customer->id, $order->customer_id);
    }

    public function test_text_from_the_customer_is_escaped_on_the_owner_pages(): void
    {
        $order = $this->order(Status::UnderReview, ['notes' => '<script>alert("owner")</script>'], User::factory()->create(['name' => '<b>Bold</b> Name']));

        $index = $this->as()->get(route('owner.orders.index'))->getContent();
        $show = $this->as()->get(route('owner.orders.show', $order))->getContent();

        $this->assertStringNotContainsString('<b>Bold</b>', $index);
        $this->assertStringNotContainsString('<script>alert("owner")</script>', $show);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; Name', $show);
    }

    public function test_the_owner_cannot_place_orders(): void
    {
        $this->as()->get(route('customer.printing.create'))->assertForbidden();
        $this->as()->post(route('customer.printing.store'), ['service' => PrintingService::factory()->published()->create()->id])->assertForbidden();
    }

    public function test_an_order_of_a_deleted_service_still_opens(): void
    {
        $order = $this->order();
        $order->service->delete();

        $this->as()->get(route('owner.orders.show', $order))->assertOk();
    }
}
