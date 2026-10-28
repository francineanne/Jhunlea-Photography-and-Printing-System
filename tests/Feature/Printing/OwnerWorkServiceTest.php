<?php

namespace Tests\Feature\Printing;

use App\Enums\OrderEvent;
use App\Enums\PrintingOrderStatus as Status;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\OrderStatusHistory;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\StatusTransitionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The owner's work that StatusTransitionService records: price on accept, information requests and price changes. */
class OwnerWorkServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->customer = User::factory()->create();
    }

    private function service(): StatusTransitionService
    {
        return app(StatusTransitionService::class);
    }

    private function order(Status $status = Status::UnderReview, array $attributes = []): PrintingOrder
    {
        return PrintingOrder::factory()->forCustomer($this->customer)->status($status)->create($attributes);
    }

    // Accepting

    public function test_accepting_saves_the_confirmed_price_with_the_status(): void
    {
        $order = $this->order();

        $this->service()->transition($order, Status::Accepted, $this->owner, null, '1250.5');

        $order->refresh();
        $this->assertSame(Status::Accepted, $order->status);
        $this->assertSame('1250.50', $order->confirmed_price);
    }

    /** @return array<string, array{?string}> */
    public static function badPrices(): array
    {
        return [
            'missing' => [null],
            'empty' => ['  '],
            'negative' => ['-1.00'],
            'three decimals' => ['10.005'],
            'too large' => ['100000000.00'],
            'words' => ['ten'],
            'exponent' => ['1e3'],
        ];
    }

    #[DataProvider('badPrices')]
    public function test_accepting_without_a_valid_price_changes_nothing(?string $price): void
    {
        $order = $this->order();

        try {
            $this->service()->transition($order, Status::Accepted, $this->owner, null, $price);
            $this->fail('The order should not have been accepted.');
        } catch (InvalidStatusTransitionException) {
            // expected
        }

        $this->assertSame(Status::UnderReview, $order->fresh()->status);
        $this->assertNull($order->fresh()->confirmed_price);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_the_price_edges_are_accepted(): void
    {
        $free = $this->order();
        $top = $this->order();

        $this->service()->transition($free, Status::Accepted, $this->owner, null, '0');
        $this->service()->transition($top, Status::Accepted, $this->owner, null, '99999999.99');

        $this->assertSame('0.00', $free->fresh()->confirmed_price);
        $this->assertSame('99999999.99', $top->fresh()->confirmed_price);
    }

    public function test_no_other_move_needs_a_price(): void
    {
        $order = $this->order(Status::Submitted);

        $this->service()->transition($order, Status::UnderReview, $this->owner);

        $this->assertSame(Status::UnderReview, $order->fresh()->status);
        $this->assertNull($order->fresh()->confirmed_price);
    }

    public function test_a_double_submit_of_the_same_move_writes_one_history_row(): void
    {
        $order = $this->order();
        $stale = PrintingOrder::find($order->id);

        $this->service()->transition($order, Status::Accepted, $this->owner, null, '100');

        try {
            $this->service()->transition($stale, Status::Accepted, $this->owner, null, '100');
            $this->fail('The second accept must be refused.');
        } catch (InvalidStatusTransitionException) {
            // expected
        }

        $this->assertSame(1, OrderStatusHistory::count());
    }

    // Asking for more information

    public function test_asking_for_information_keeps_the_status_and_writes_a_history_row(): void
    {
        $order = $this->order();

        $this->service()->requestInfo($order, $this->owner, '  Please send the file as a PDF.  ');

        $order->refresh();
        $this->assertSame(Status::UnderReview, $order->status);
        $this->assertSame('Please send the file as a PDF.', $order->info_request);
        $this->assertTrue($order->needsInfo());

        $row = OrderStatusHistory::sole();
        $this->assertSame(OrderEvent::InfoRequested, $row->event);
        $this->assertSame(Status::UnderReview, $row->from_status);
        $this->assertSame(Status::UnderReview, $row->to_status);
        $this->assertSame('Please send the file as a PDF.', $row->note);
        $this->assertSame($this->owner->id, $row->changed_by);
    }

    public function test_the_same_request_sent_twice_writes_one_row(): void
    {
        $order = $this->order();

        $this->service()->requestInfo($order, $this->owner, 'Send a PDF.');
        $this->service()->requestInfo($order, $this->owner, 'Send a PDF.');

        $this->assertSame(1, OrderStatusHistory::count());
    }

    public function test_information_can_be_asked_for_only_while_the_customer_can_answer(): void
    {
        foreach ([Status::Submitted, Status::UnderReview] as $status) {
            $this->service()->requestInfo($this->order($status), $this->owner, 'Need more.');
        }

        foreach ([Status::Accepted, Status::Processing, Status::ReadyForPickup, Status::Completed, Status::Rejected, Status::Cancelled] as $status) {
            try {
                $this->service()->requestInfo($this->order($status), $this->owner, 'Need more.');
                $this->fail("Asking must be refused while {$status->value}.");
            } catch (InvalidStatusTransitionException) {
                // expected
            }
        }

        $this->assertSame(2, OrderStatusHistory::count());
    }

    public function test_a_customer_cannot_ask_for_information(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service()->requestInfo($this->order(), $this->customer, 'Me?');
    }

    public function test_an_empty_request_is_refused(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);

        $this->service()->requestInfo($this->order(), $this->owner, '   ');
    }

    // The clearing rule

    public function test_a_file_from_the_customer_answers_the_request(): void
    {
        $order = $this->order(Status::UnderReview, ['info_request' => 'Send a PDF.']);

        $this->service()->recordFilesAdded($order, $this->customer, 2);

        $this->assertNull($order->fresh()->info_request);
        $row = OrderStatusHistory::sole();
        $this->assertSame(OrderEvent::InfoAnswered, $row->event);
        $this->assertSame('Added 2 files.', $row->note);
        $this->assertSame($this->customer->id, $row->changed_by);
    }

    public function test_a_file_added_by_the_owner_does_not_answer_the_request(): void
    {
        $order = $this->order(Status::UnderReview, ['info_request' => 'Send a PDF.']);

        $this->service()->recordFilesAdded($order, $this->owner, 1);

        $this->assertSame('Send a PDF.', $order->fresh()->info_request);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_files_added_when_nothing_was_asked_write_nothing(): void
    {
        $order = $this->order();

        $this->service()->recordFilesAdded($order, $this->customer, 1);

        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_moving_the_order_on_clears_the_request(): void
    {
        $accepted = $this->order(Status::UnderReview, ['info_request' => 'Send a PDF.']);
        $rejected = $this->order(Status::UnderReview, ['info_request' => 'Send a PDF.']);
        $cancelled = $this->order(Status::Submitted, ['info_request' => 'Send a PDF.']);

        $this->service()->transition($accepted, Status::Accepted, $this->owner, null, '10');
        $this->service()->transition($rejected, Status::Rejected, $this->owner, 'Unreadable.');
        $this->service()->transition($cancelled, Status::Cancelled, $this->customer);

        foreach ([$accepted, $rejected, $cancelled] as $order) {
            $this->assertNull($order->fresh()->info_request);
        }
    }

    public function test_another_customer_cannot_answer_for_the_order(): void
    {
        $order = $this->order(Status::UnderReview, ['info_request' => 'Send a PDF.']);

        $this->expectException(AuthorizationException::class);

        $this->service()->recordFilesAdded($order, User::factory()->create(), 1);
    }

    // Changing the confirmed price

    public function test_the_price_can_change_while_accepted_or_processing_with_a_history_note(): void
    {
        foreach ([Status::Accepted, Status::Processing] as $status) {
            $order = $this->order($status, ['confirmed_price' => '100.00']);

            $this->service()->changePrice($order, $this->owner, '120.50', 'Thicker paper.');

            $this->assertSame('120.50', $order->fresh()->confirmed_price);
            $this->assertSame($status, $order->fresh()->status);
        }

        $row = OrderStatusHistory::latest('id')->first();
        $this->assertSame(OrderEvent::PriceChanged, $row->event);
        $this->assertSame('Confirmed price changed from ₱100.00 to ₱120.50. Thicker paper.', $row->note);
    }

    public function test_the_price_cannot_change_after_processing(): void
    {
        foreach ([Status::Submitted, Status::UnderReview, Status::ReadyForPickup, Status::Completed, Status::Rejected, Status::Cancelled] as $status) {
            $order = $this->order($status, ['confirmed_price' => '100.00']);

            try {
                $this->service()->changePrice($order, $this->owner, '1.00');
                $this->fail("Changing the price must be refused while {$status->value}.");
            } catch (InvalidStatusTransitionException) {
                // expected
            }

            $this->assertSame('100.00', $order->fresh()->confirmed_price);
        }

        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_the_same_price_twice_writes_one_row_and_a_customer_cannot_change_it(): void
    {
        $order = $this->order(Status::Accepted, ['confirmed_price' => '100.00']);

        $this->service()->changePrice($order, $this->owner, '150');
        $this->service()->changePrice($order, $this->owner, '150.00');
        $this->assertSame(1, OrderStatusHistory::count());

        $this->expectException(AuthorizationException::class);
        $this->service()->changePrice($order, $this->customer, '1');
    }
}
