<?php

namespace Tests\Feature\Printing;

use App\Enums\PrintingOrderStatus as Status;
use App\Enums\UserRole;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\OrderStatusHistory;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\StatusTransitionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatusTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    /** The moves the owner may make, written out here by hand so the test does not just echo the enum. */
    private const OWNER_MOVES = [
        'submitted>under_review', 'submitted>cancelled',
        'under_review>accepted', 'under_review>rejected', 'under_review>cancelled',
        'accepted>processing', 'accepted>cancelled',
        'processing>ready_for_pickup',
        'ready_for_pickup>completed',
    ];

    /** The only move a customer may make. */
    private const CUSTOMER_MOVES = ['submitted>cancelled'];

    private function service(): StatusTransitionService
    {
        return app(StatusTransitionService::class);
    }

    /** @return array<string, array{Status, Status}> */
    public static function everyPair(): array
    {
        $pairs = [];

        foreach (Status::cases() as $from) {
            foreach (Status::cases() as $to) {
                $pairs["{$from->value} to {$to->value}"] = [$from, $to];
            }
        }

        return $pairs;
    }

    /** @return array<string, array{Status, Status}> */
    public static function customerDeniedPairs(): array
    {
        return array_filter(self::everyPair(), fn (array $pair) => ! in_array("{$pair[0]->value}>{$pair[1]->value}", self::CUSTOMER_MOVES, true));
    }

    #[DataProvider('everyPair')]
    public function test_the_owner_can_make_exactly_the_listed_moves(Status $from, Status $to): void
    {
        $owner = User::factory()->owner()->create();
        // Phase 5A: completing needs a settled order; a ₱0.00 price needs no payment
        $order = PrintingOrder::factory()->status($from)->create($to === Status::Completed ? ['confirmed_price' => '0.00'] : []);
        $allowed = in_array("{$from->value}>{$to->value}", self::OWNER_MOVES, true);

        if (! $allowed) {
            $this->expectException(InvalidStatusTransitionException::class);
        }

        // Accepting also needs the confirmed price; every other move ignores it
        $this->service()->transition($order, $to, $owner, 'A reason', '150.00');

        $this->assertSame($to, $order->fresh()->status);
        $this->assertSame(1, OrderStatusHistory::where('printing_order_id', $order->id)->count());
    }

    #[DataProvider('everyPair')]
    public function test_a_customer_can_make_only_the_cancel_move_on_their_own_order(Status $from, Status $to): void
    {
        $customer = User::factory()->create();
        $order = PrintingOrder::factory()->forCustomer($customer)->status($from)->create();
        $allowed = in_array("{$from->value}>{$to->value}", self::CUSTOMER_MOVES, true);

        if (! $allowed) {
            $this->expectException(InvalidStatusTransitionException::class);
        }

        $this->service()->transition($order, $to, $customer);

        $this->assertSame($to, $order->fresh()->status);
    }

    #[DataProvider('customerDeniedPairs')]
    public function test_a_denied_move_changes_nothing_and_writes_no_history(Status $from, Status $to): void
    {
        $customer = User::factory()->create();
        $order = PrintingOrder::factory()->forCustomer($customer)->status($from)->create();

        try {
            $this->service()->transition($order, $to, $customer);
            $this->fail('The move should have been refused.');
        } catch (InvalidStatusTransitionException) {
            // expected
        }

        $this->assertSame($from, $order->fresh()->status);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_the_table_agrees_with_the_hand_written_lists(): void
    {
        $fromEnum = [];

        foreach (Status::cases() as $from) {
            foreach ($from->transitions() as $to => $roles) {
                foreach ($roles as $role) {
                    $fromEnum[$role->value][] = "{$from->value}>{$to}";
                }
            }
        }

        $this->assertEqualsCanonicalizing(self::OWNER_MOVES, $fromEnum[UserRole::Owner->value]);
        $this->assertEqualsCanonicalizing(self::CUSTOMER_MOVES, $fromEnum[UserRole::Customer->value]);
    }

    public function test_rejected_completed_and_cancelled_are_final(): void
    {
        foreach ([Status::Rejected, Status::Completed, Status::Cancelled] as $status) {
            $this->assertTrue($status->isFinal());
            $this->assertSame([], $status->transitions());
        }

        $this->assertFalse(Status::Submitted->isFinal());
    }

    public function test_every_change_is_written_to_the_history_with_who_when_from_to_and_note(): void
    {
        $owner = User::factory()->owner()->create();
        $order = PrintingOrder::factory()->status(Status::Submitted)->create();

        $this->service()->transition($order, Status::UnderReview, $owner, 'Looking at the file');
        $this->service()->transition($order, Status::Accepted, $owner, null, '150.00');

        $rows = $order->statusHistories()->get();

        $this->assertCount(2, $rows);
        $this->assertSame(Status::Submitted, $rows[0]->from_status);
        $this->assertSame(Status::UnderReview, $rows[0]->to_status);
        $this->assertSame($owner->id, $rows[0]->changed_by);
        $this->assertSame('Looking at the file', $rows[0]->note);
        $this->assertNotNull($rows[0]->created_at);
        $this->assertSame(Status::UnderReview, $rows[1]->from_status);
        $this->assertNull($rows[1]->note);
    }

    public function test_rejecting_needs_a_reason_and_keeps_it_on_the_order(): void
    {
        $owner = User::factory()->owner()->create();
        $order = PrintingOrder::factory()->status(Status::UnderReview)->create();

        foreach ([null, '', '   '] as $blank) {
            try {
                $this->service()->transition($order, Status::Rejected, $owner, $blank);
                $this->fail('A rejection without a reason must be refused.');
            } catch (InvalidStatusTransitionException) {
                // expected
            }
        }

        $this->assertSame(Status::UnderReview, $order->fresh()->status);

        $this->service()->transition($order, Status::Rejected, $owner, '  The file is blurry.  ');

        $this->assertSame('The file is blurry.', $order->fresh()->rejection_reason);
        $this->assertSame('The file is blurry.', $order->statusHistories()->first()->note);
    }

    public function test_the_instance_the_caller_holds_is_updated_too(): void
    {
        $owner = User::factory()->owner()->create();
        $order = PrintingOrder::factory()->create();

        $this->service()->transition($order, Status::UnderReview, $owner);

        $this->assertSame(Status::UnderReview, $order->status);
        $this->assertFalse($order->isDirty());
    }

    public function test_a_customer_cannot_touch_another_customers_order(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $order = PrintingOrder::factory()->forCustomer($a)->create();

        $this->expectException(AuthorizationException::class);

        try {
            $this->service()->transition($order, Status::Cancelled, $b);
        } finally {
            $this->assertSame(Status::Submitted, $order->fresh()->status);
            $this->assertSame(0, OrderStatusHistory::count());
        }
    }

    public function test_an_old_copy_of_the_order_cannot_be_used_to_skip_the_rules(): void
    {
        $customer = User::factory()->create();
        $owner = User::factory()->owner()->create();
        $stale = PrintingOrder::factory()->forCustomer($customer)->create();

        // The owner accepts the order after the customer's page was loaded
        $copy = PrintingOrder::find($stale->id);
        $this->service()->transition($copy, Status::UnderReview, $owner);
        $this->service()->transition($copy, Status::Accepted, $owner, null, '150.00');

        $this->assertSame(Status::Submitted, $stale->status);

        $this->expectException(InvalidStatusTransitionException::class);

        $this->service()->transition($stale, Status::Cancelled, $customer);
    }

    public function test_the_status_and_the_history_row_are_saved_together_or_not_at_all(): void
    {
        $order = PrintingOrder::factory()->create();
        $ghost = User::factory()->owner()->make(['id' => 987654]);

        try {
            $this->service()->transition($order, Status::UnderReview, $ghost);
            $this->fail('The history row cannot be saved for a user that does not exist.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(Status::Submitted, $order->fresh()->status);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_a_deleted_order_cannot_change_status(): void
    {
        $owner = User::factory()->owner()->create();
        $order = PrintingOrder::factory()->create();
        $order->delete();

        $this->expectException(ModelNotFoundException::class);

        $this->service()->transition($order, Status::UnderReview, $owner);
    }

    public function test_status_cannot_be_set_through_mass_assignment(): void
    {
        $order = new PrintingOrder;
        $order->fill(['status' => 'completed', 'customer_id' => 5, 'estimated_price' => '1.00', 'confirmed_price' => '1.00', 'reference_number' => 'PR-1', 'rejection_reason' => 'x', 'quantity' => 3, 'notes' => 'hi']);

        $this->assertNull($order->status);
        $this->assertNull($order->customer_id);
        $this->assertNull($order->estimated_price);
        $this->assertNull($order->confirmed_price);
        $this->assertNull($order->reference_number);
        $this->assertNull($order->rejection_reason);
        $this->assertSame(3, $order->quantity);
        $this->assertSame('hi', $order->notes);
    }
}
