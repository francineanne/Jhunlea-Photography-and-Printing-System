<?php

namespace Tests\Feature\Printing;

use App\Enums\PrintingOrderStatus as Status;
use App\Models\OrderFile;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PrintingOrderPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
        $this->owner = User::factory()->owner()->create();
    }

    public function test_a_customer_sees_their_own_order_and_not_another_customers(): void
    {
        $order = PrintingOrder::factory()->forCustomer($this->alice)->create();

        $this->assertTrue(Gate::forUser($this->alice)->allows('view', $order));
        $this->assertFalse(Gate::forUser($this->bob)->allows('view', $order));
    }

    public function test_the_owner_sees_every_order(): void
    {
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', PrintingOrder::factory()->forCustomer($this->alice)->create()));
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', PrintingOrder::factory()->forCustomer($this->bob)->create()));
    }

    public function test_a_customer_cancels_only_their_own_order_and_only_while_it_is_submitted(): void
    {
        $submitted = PrintingOrder::factory()->forCustomer($this->alice)->create();
        $inReview = PrintingOrder::factory()->forCustomer($this->alice)->status(Status::UnderReview)->create();

        $this->assertTrue(Gate::forUser($this->alice)->allows('cancel', $submitted));
        $this->assertFalse(Gate::forUser($this->bob)->allows('cancel', $submitted));
        $this->assertFalse(Gate::forUser($this->alice)->allows('cancel', $inReview));
    }

    public function test_only_the_owner_can_change_a_status(): void
    {
        $order = PrintingOrder::factory()->forCustomer($this->alice)->create();

        $this->assertTrue(Gate::forUser($this->owner)->allows('changeStatus', $order));
        $this->assertFalse(Gate::forUser($this->alice)->allows('changeStatus', $order));
        $this->assertFalse(Gate::forUser($this->bob)->allows('changeStatus', $order));
    }

    public function test_only_customers_place_orders(): void
    {
        $this->assertTrue(Gate::forUser($this->alice)->allows('create', PrintingOrder::class));
        $this->assertFalse(Gate::forUser($this->owner)->allows('create', PrintingOrder::class));
    }

    public function test_files_can_be_added_by_the_order_customer_and_the_owner_only_while_the_order_is_open(): void
    {
        $open = PrintingOrder::factory()->forCustomer($this->alice)->create();
        $closed = PrintingOrder::factory()->forCustomer($this->alice)->status(Status::Processing)->create();

        $this->assertTrue(Gate::forUser($this->alice)->allows('addFiles', $open));
        $this->assertTrue(Gate::forUser($this->owner)->allows('addFiles', $open));
        $this->assertFalse(Gate::forUser($this->bob)->allows('addFiles', $open));
        $this->assertFalse(Gate::forUser($this->alice)->allows('addFiles', $closed));
        $this->assertFalse(Gate::forUser($this->owner)->allows('addFiles', $closed));
    }

    public function test_a_file_can_be_seen_only_by_its_customer_and_the_owner(): void
    {
        $file = OrderFile::factory()->for(PrintingOrder::factory()->forCustomer($this->alice), 'order')->create();

        $this->assertTrue(Gate::forUser($this->alice)->allows('view', $file));
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $file));
        $this->assertFalse(Gate::forUser($this->bob)->allows('view', $file));
    }

    public function test_over_http_another_customers_order_answers_403_and_the_customers_own_answers_200(): void
    {
        Route::middleware(['web', 'auth', 'role:customer'])->get('/__test/orders/{order}', function (PrintingOrder $order) {
            Gate::authorize('view', $order);

            return $order->reference_number;
        });

        $order = PrintingOrder::factory()->forCustomer($this->alice)->create();

        $this->actingAs($this->bob)->get("/__test/orders/{$order->id}")->assertForbidden();
        $this->actingAs($this->alice)->get("/__test/orders/{$order->id}")->assertOk()->assertSee($order->reference_number);
    }

    public function test_a_customer_on_an_owner_route_gets_403_even_for_an_id_that_does_not_exist(): void
    {
        Route::middleware(['web', 'auth', 'role:owner'])->get('/__test/owner-orders/{order}', fn (PrintingOrder $order) => $order->reference_number);

        $this->actingAs($this->alice)->get('/__test/owner-orders/999999')->assertForbidden();
        $this->actingAs($this->owner)->get('/__test/owner-orders/999999')->assertNotFound();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        Route::middleware(['web', 'auth', 'role:customer'])->get('/__test/orders/{order}', fn (PrintingOrder $order) => 'x');

        $order = PrintingOrder::factory()->create();

        $this->get("/__test/orders/{$order->id}")->assertRedirect('/login');
    }
}
