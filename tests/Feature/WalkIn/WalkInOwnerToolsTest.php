<?php

namespace Tests\Feature\WalkIn;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Phase 8A: the owner's Ask Lumi tools keep working with walk-in customers, orders and bookings (no prompt change). */
class WalkInOwnerToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_tool_reads_walk_in_rows_without_failing(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 02:00:00', 'UTC'));
        $owner = User::factory()->owner()->create();
        $walkIn = User::factory()->walkIn()->create(['name' => 'Lola Counter', 'phone' => '0917 555 0001']);
        $order = PrintingOrder::factory()->walkIn()->forCustomer($walkIn)->status(PrintingOrderStatus::Accepted)->create(['confirmed_price' => '80.00', 'reference_number' => 'PR-900001']);
        $booking = PhotographyBooking::factory()->walkIn()->forCustomer($walkIn)->status(BookingStatus::Confirmed)
            ->at(Carbon::parse('2026-11-05 06:00:00', 'UTC'))->create(['confirmed_price' => '1500.00', 'reference_number' => 'PB-900001']);
        $tools = app(OwnerTools::class);

        $params = [
            'customer_search' => ['name' => 'Lola'],
            'customer_profile' => ['customer_id' => $walkIn->id],
            'order_lookup' => ['reference' => $order->reference_number],
            'booking_lookup' => ['reference' => $booking->reference_number],
            'slot_availability' => ['date' => '2026-11-05'],
        ];

        // how_to reads no records (it explains where things are), so it is not part of this check
        foreach (array_diff($tools->names(), ['how_to']) as $name) {
            $tool = $tools->get($name);
            $result = $tool->execute($params[$name] ?? [], $owner);
            $this->assertSame($name, $result->tool, $name);
        }

        $found = $tools->get('customer_search')->execute(['name' => 'Lola'], $owner);
        $this->assertStringContainsString('Lola Counter', json_encode($found->rows).json_encode($found->data));
        // Never a phone number or an email in what the tools hand to the model
        $this->assertStringNotContainsString('0917 555 0001', json_encode($found->facts()));
        $this->assertNull($tools->get('order_lookup')->execute(['reference' => 'PR-900001'], $owner)->question);
    }
}
