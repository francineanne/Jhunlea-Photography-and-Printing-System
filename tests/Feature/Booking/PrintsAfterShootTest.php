<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus as Status;
use App\Enums\OptionGroup;
use App\Enums\PricingType;
use App\Enums\PrintingOrderStatus;
use App\Models\OrderFile;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Models\User;
use App\Services\PrintingOrderService;
use App\Services\StatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Phase 4D: prints ordered after a completed photoshoot, linked both ways, prices kept apart. */
class PrintsAfterShootTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private User $customer;

    private User $owner;

    private PrintingService $service;

    private PrintingServiceOption $size;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->customer = User::factory()->create();
        $this->owner = User::factory()->owner()->create();
        $this->service = PrintingService::factory()->published()->create([
            'name' => 'Photo Prints', 'pricing_type' => PricingType::PerCopy, 'base_price' => '20.00',
            'allowed_file_types' => ['pdf', 'jpg', 'png'], 'max_file_size_mb' => 2,
        ]);
        $this->size = PrintingServiceOption::factory()->create(['printing_service_id' => $this->service->id, 'group' => OptionGroup::PrintSize, 'label' => '4R', 'price_modifier' => '0.00']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function booking(Status $status = Status::Completed, ?User $customer = null): PhotographyBooking
    {
        return PhotographyBooking::factory()->forCustomer($customer ?? $this->customer)->status($status)->create(['confirmed_price' => '1850.00']);
    }

    private function upload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, self::PDF);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'photos.pdf', null, null, true);
    }

    private function send(array $extra, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->customer)->from(route('customer.printing.create'))->post(route('customer.printing.store'), [
            'service' => $this->service->id,
            'options' => [$this->service->id => ['print_size' => $this->size->id]],
            'quantity' => 3,
            'files' => [$this->upload()],
        ] + $extra);
    }

    private function assertNothingSaved(): void
    {
        $this->assertSame(0, PrintingOrder::withTrashed()->count());
        $this->assertSame(0, OrderFile::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_completed_booking_offers_order_prints_and_others_do_not(): void
    {
        $done = $this->booking();
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $done))
            ->assertSee('Order prints')
            ->assertSee(e(route('customer.printing.create', ['booking' => $done->id])), false);

        foreach ([Status::Requested, Status::Proposed, Status::Confirmed, Status::Rejected, Status::Cancelled] as $status) {
            $this->actingAs($this->customer)->get(route('customer.bookings.show', $this->booking($status)))
                ->assertDontSee('Order prints')->assertSee('Prints can be ordered once the photoshoot is completed.');
        }
    }

    public function test_the_wizard_attaches_my_completed_booking_only(): void
    {
        $done = $this->booking();
        $this->actingAs($this->customer)->get(route('customer.printing.create', ['booking' => $done->id]))->assertOk()
            ->assertSee('Prints from your photoshoot')
            ->assertSee($done->reference_number)
            ->assertSee('<input type="hidden" name="booking" value="'.$done->id.'">', false)
            ->assertSee('separate from the photography fee');

        $theirs = $this->booking(customer: User::factory()->create());
        $notDone = $this->booking(Status::Confirmed);

        foreach ([$theirs->id, $notDone->id, 999999, 'abc', '1 OR 1=1', '-1'] as $tampered) {
            $this->actingAs($this->customer)->get(route('customer.printing.create', ['booking' => $tampered]))->assertOk()
                ->assertSee('That photoshoot cannot have prints attached')
                ->assertDontSee('name="booking"', false)
                ->assertDontSee($theirs->reference_number);
        }
    }

    public function test_an_order_is_linked_and_the_booking_price_never_changes(): void
    {
        $done = $this->booking();

        $this->send(['booking' => $done->id])->assertRedirect();

        $order = PrintingOrder::sole();
        $this->assertSame($done->id, $order->photography_booking_id);
        $this->assertSame('60.00', $order->estimated_price);
        $fresh = $done->fresh();
        $this->assertSame('1850.00', $fresh->confirmed_price);
        $this->assertSame(Status::Completed, $fresh->status);
    }

    public function test_a_booking_that_is_not_mine_or_not_completed_is_refused_and_nothing_is_saved(): void
    {
        $theirs = $this->booking(customer: User::factory()->create());

        foreach ([$theirs->id, $this->booking(Status::Confirmed)->id, $this->booking(Status::Cancelled)->id, 999999, 'abc'] as $tampered) {
            $this->send(['booking' => $tampered])->assertSessionHasErrors(['booking' => PrintingOrderService::BOOKING_MESSAGE]);
            $this->assertNothingSaved();
        }
    }

    public function test_the_service_checks_the_booking_again(): void
    {
        $theirs = $this->booking(customer: User::factory()->create());

        $this->expectException(ValidationException::class);
        app(PrintingOrderService::class)->place($this->customer, $this->service, [$this->size->id], 1, 1, null, $theirs);
    }

    public function test_the_link_cannot_be_set_through_other_fields(): void
    {
        $done = $this->booking();

        $this->send(['photography_booking_id' => $done->id])->assertRedirect();

        $this->assertNull(PrintingOrder::sole()->photography_booking_id);
    }

    public function test_a_booking_without_the_prints_preference_can_still_order_prints(): void
    {
        $done = PhotographyBooking::factory()->forCustomer($this->customer)->status(Status::Completed)->create(['wants_prints' => false]);

        $this->send(['booking' => $done->id])->assertRedirect();

        $this->assertSame($done->id, PrintingOrder::sole()->photography_booking_id);
    }

    public function test_the_link_shows_both_ways_for_the_customer_and_the_owner(): void
    {
        $done = $this->booking();
        $this->send(['booking' => $done->id]);
        $order = PrintingOrder::sole();

        $this->actingAs($this->customer)->get(route('customer.printing.show', $order))
            ->assertSee('From booking')->assertSee(route('customer.bookings.show', $done), false)->assertSee($done->reference_number);
        $this->actingAs($this->owner)->get(route('owner.orders.show', $order))
            ->assertSee('From booking')->assertSee(route('owner.bookings.show', $done), false);

        $this->actingAs($this->customer)->get(route('customer.bookings.show', $done))
            ->assertSee($order->reference_number)->assertSee(route('customer.printing.show', $order), false)->assertSee('₱60.00');
        $this->actingAs($this->owner)->get(route('owner.bookings.show', $done))
            ->assertSee('Printing orders from this shoot')->assertSee(route('owner.orders.show', $order), false);
    }

    public function test_a_wrongly_linked_order_never_shows_another_customers_booking(): void
    {
        $other = User::factory()->create();
        $theirs = $this->booking(customer: $other);
        $this->send([]);
        $order = PrintingOrder::sole();
        $order->forceFill(['photography_booking_id' => $theirs->id])->save();

        $this->actingAs($this->customer)->get(route('customer.printing.show', $order))
            ->assertOk()->assertDontSee($theirs->reference_number)->assertDontSee('From booking');
        $this->actingAs($this->customer)->get(route('customer.printing.index'))->assertDontSee($theirs->reference_number);
        $this->actingAs($other)->get(route('customer.bookings.show', $theirs))->assertDontSee($order->reference_number);
    }

    public function test_cancelling_the_booking_or_rejecting_the_order_changes_nothing_else(): void
    {
        $done = $this->booking();
        $this->send(['booking' => $done->id]);
        $order = PrintingOrder::sole();

        app(StatusTransitionService::class)->transition($order, PrintingOrderStatus::UnderReview, $this->owner);
        app(StatusTransitionService::class)->transition($order->fresh(), PrintingOrderStatus::Rejected, $this->owner, 'Files too small.');

        $this->assertSame(Status::Completed, $done->fresh()->status);
        $this->assertSame($done->id, $order->fresh()->photography_booking_id);
    }

    public function test_the_owner_cannot_use_the_customer_wizard(): void
    {
        $this->actingAs($this->owner)->get(route('customer.printing.create', ['booking' => $this->booking()->id]))->assertForbidden();
        $this->send(['booking' => $this->booking()->id], $this->owner)->assertForbidden();
        $this->assertSame(0, PrintingOrder::count());
    }
}
