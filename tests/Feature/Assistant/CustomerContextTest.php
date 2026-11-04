<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\CustomerContextBuilder;
use App\Services\Assistant\CustomerRecords;
use App\Services\Assistant\PromptBuilder;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * The signed-in customer's own records in the prompt (Phase 7E-1): only what their own My orders and My bookings pages
 * show, for them alone, in a fixed number of queries.
 */
class CustomerContextTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->customer = User::factory()->create(['name' => 'Maricel Santos', 'email' => 'maricel.private@example.test', 'phone' => '09171112222']);
    }

    private function block(?User $customer = null, AssistantFeature $feature = AssistantFeature::AskLumi): string
    {
        $this->ask('Kumusta ang order ko?', $feature, AssistantActor::user(($customer ?? $this->customer)->id));

        return $this->fake->lastRequest()->systemInstruction;
    }

    private function line(string $system, string $reference): string
    {
        $this->assertSame(1, preg_match('/^- .*'.preg_quote($reference, '/').'.*$/m', $system, $match), "{$reference} is not in the block");

        return $match[0];
    }

    public function test_each_record_says_what_the_customer_pages_say(): void
    {
        $awaiting = PrintingOrder::factory()->forCustomer($this->customer)->create(['reference_number' => 'PR-000101', 'estimated_price' => '250.00']);
        $unpaid = PrintingOrder::factory()->forCustomer($this->customer)->status(PrintingOrderStatus::Accepted)->create(['reference_number' => 'PR-000102', 'confirmed_price' => '1500.00']);
        $ready = PrintingOrder::factory()->forCustomer($this->customer)->status(PrintingOrderStatus::ReadyForPickup)->create(['reference_number' => 'PR-000103', 'confirmed_price' => '320.00']);
        Payment::factory()->paying($ready)->create();
        $completed = PrintingOrder::factory()->forCustomer($this->customer)->status(PrintingOrderStatus::Completed)->create(['reference_number' => 'PR-000104', 'confirmed_price' => '80.00']);
        Payment::factory()->paying($completed)->create();
        $start = Carbon::now('UTC')->addDays(5)->setTime(2, 0);
        $booking = PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Confirmed)->at($start)->create(['reference_number' => 'PB-000201', 'confirmed_price' => '3000.00']);

        $system = $this->block();

        $line = $this->line($system, 'PR-000101');
        $this->assertStringContainsString('Printing order PR-000101 (type "order", id '.$awaiting->id.'): Sample service.', $line);
        $this->assertStringContainsString('Status: Submitted.', $line);
        $this->assertStringContainsString('Price: Awaiting price (estimate ₱250.00, not final).', $line);
        $this->assertStringContainsString('Payment: Awaiting price; nothing to pay until the shop confirms the price.', $line);
        $this->assertStringContainsString('The customer can cancel it online now.', $line);

        $line = $this->line($system, 'PR-000102');
        $this->assertStringContainsString('Status: Accepted.', $line);
        $this->assertStringContainsString('Price: '.$unpaid->confirmedText().' (confirmed).', $line);
        $this->assertStringContainsString('Unpaid; ₱1,500.00 to pay in full at the shop, in one payment (cash or GCash).', $line);
        $this->assertStringContainsString('The customer cannot cancel it online now.', $line);

        $line = $this->line($system, 'PR-000103');
        $this->assertStringContainsString('Status: Ready for pickup.', $line);
        $this->assertStringContainsString('Paid in full, ₱320.00 recorded by the shop; nothing left to pay.', $line);

        $line = $this->line($system, 'PR-000104');
        $this->assertStringContainsString('Status: Completed.', $line);
        $this->assertStringNotContainsString('cancel', $line, 'A finished record says nothing about cancelling');

        $line = $this->line($system, 'PB-000201');
        $this->assertStringContainsString('Photoshoot booking PB-000201 (type "booking", id '.$booking->id.'): Sample package.', $line);
        $this->assertStringContainsString('Confirmed session: '.LocalTime::format($booking->starts_at, CustomerContextBuilder::SESSION_FORMAT).'.', $line);
        $this->assertStringContainsString('Unpaid; ₱3,000.00 to pay in full at the studio on the day of the shoot', $line);
        $this->assertStringContainsString('The customer can cancel it online now.', $line);
        $this->assertStringContainsString('Last update: ', $line);

        // The same date and amount the customer's own booking page shows
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $booking))->assertOk()
            ->assertSee(LocalTime::format($booking->starts_at, 'D, M j, Y'))->assertSee(LocalTime::format($booking->starts_at, 'g:i A'))->assertSee('₱3,000.00');
    }

    public function test_requested_and_proposed_times_cancel_rules_and_the_shops_question(): void
    {
        $soon = Carbon::now('UTC')->addHours(10);
        PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Confirmed)->at($soon)->create(['reference_number' => 'PB-000301', 'confirmed_price' => '2000.00']);
        $requested = PhotographyBooking::factory()->forCustomer($this->customer)->create(['reference_number' => 'PB-000302']);
        $proposed = PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Proposed)->create([
            'reference_number' => 'PB-000303', 'proposed_starts_at' => Carbon::now('UTC')->addDays(12)->setTime(3, 0), 'proposed_note' => 'Sorry, only mornings',
        ]);
        PrintingOrder::factory()->forCustomer($this->customer)->status(PrintingOrderStatus::UnderReview)->create(['reference_number' => 'PR-000304', 'info_request' => 'Please send a sharper scan of page 4']);

        $system = $this->block();

        $this->assertStringContainsString('The customer cannot cancel it online now.', $this->line($system, 'PB-000301'), 'Within the cutoff');
        $this->assertStringContainsString('Requested time (not confirmed yet): '.LocalTime::format($requested->starts_at, CustomerContextBuilder::SESSION_FORMAT), $this->line($system, 'PB-000302'));
        $this->assertStringContainsString('Price: Awaiting price (estimate ₱1,000.00, not final).', $this->line($system, 'PB-000302'));
        $line = $this->line($system, 'PB-000303');
        $this->assertStringContainsString('Status: New time proposed.', $line);
        $this->assertStringContainsString('The studio proposed a new time: '.LocalTime::format($proposed->proposed_starts_at, CustomerContextBuilder::SESSION_FORMAT), $line);
        $this->assertStringContainsString('The shop asked the customer for more information', $this->line($system, 'PR-000304'));
        $this->assertStringNotContainsString('sharper scan', $system);
        $this->assertStringNotContainsString('only mornings', $system);
    }

    public function test_a_voided_payment_is_ignored(): void
    {
        $order = PrintingOrder::factory()->forCustomer($this->customer)->status(PrintingOrderStatus::Processing)->create(['reference_number' => 'PR-000401', 'confirmed_price' => '700.00']);
        Payment::factory()->paying($order)->voided()->create(['reference_number' => 'PAY-000401']);

        $line = $this->line($this->block(), 'PR-000401');

        $this->assertStringContainsString('Unpaid; ₱700.00 to pay in full', $line);
        $this->assertStringNotContainsString('Paid in full', $line);
        $this->assertStringNotContainsString('PAY-000401', $line);
    }

    public function test_no_free_text_contact_detail_file_or_other_customer_reaches_the_block(): void
    {
        PrintingOrder::factory()->forCustomer($this->customer)->rejected('Blurry scan from Maricel')->create(['reference_number' => 'PR-000501', 'notes' => 'Deliver to Purok 7 Secret Street']);
        PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Cancelled)->create([
            'reference_number' => 'PB-000502', 'notes' => 'Surprise for Liza', 'internal_notes' => 'VIP owner note', 'contact_number' => '09185556666', 'cancellation_reason' => 'Family emergency',
        ]);
        $other = User::factory()->create(['name' => 'Other Person']);
        PrintingOrder::factory()->forCustomer($other)->create(['reference_number' => 'PR-000599', 'confirmed_price' => '4321.00']);
        PhotographyBooking::factory()->forCustomer($other)->create(['reference_number' => 'PB-000599']);

        $system = $this->block();

        $this->assertStringContainsString('PR-000501', $system);
        $this->assertStringContainsString('Status: Rejected.', $system);
        $this->assertStringContainsString('Payment: Nothing to pay.', $this->line($system, 'PR-000501'));

        foreach (['Blurry scan', 'Purok 7', 'Surprise for Liza', 'VIP owner note', '09185556666', 'Family emergency', 'Maricel', 'Santos',
            'maricel.private@example.test', '09171112222', 'PR-000599', 'PB-000599', 'Other Person', '4,321', '0917 123 4567'] as $private) {
            $this->assertStringNotContainsString($private, $system, "{$private} reached the prompt");
        }
    }

    public function test_at_most_five_open_and_three_finished_newest_first(): void
    {
        foreach (range(1, 7) as $n) {
            PrintingOrder::factory()->forCustomer($this->customer)->create(['reference_number' => sprintf('PR-0006%02d', $n), 'created_at' => now()->subDays(20 - $n)]);
        }

        foreach (range(1, 5) as $n) {
            PrintingOrder::factory()->forCustomer($this->customer)->status(PrintingOrderStatus::Completed)->create(['reference_number' => sprintf('PR-0007%02d', $n), 'updated_at' => now()->subDays(10 - $n)]);
        }

        $system = $this->block();

        foreach (['PR-000603', 'PR-000604', 'PR-000605', 'PR-000606', 'PR-000607', 'PR-000703', 'PR-000704', 'PR-000705'] as $kept) {
            $this->assertStringContainsString($kept, $system);
        }

        foreach (['PR-000601', 'PR-000602', 'PR-000701', 'PR-000702'] as $dropped) {
            $this->assertStringNotContainsString($dropped, $system);
        }

        $this->assertLessThan(strpos($system, 'PR-000703'), strpos($system, 'PR-000603'), 'Open records come first');
    }

    public function test_the_character_cap_drops_the_oldest_finished_first(): void
    {
        config(['assistant.customer_context_max_chars' => 900]);
        PrintingOrder::factory()->forCustomer($this->customer)->create(['reference_number' => 'PR-000801']);
        PrintingOrder::factory()->forCustomer($this->customer)->status(PrintingOrderStatus::Completed)->create(['reference_number' => 'PR-000802', 'updated_at' => now()->subDays(1)]);
        PrintingOrder::factory()->forCustomer($this->customer)->status(PrintingOrderStatus::Completed)->create(['reference_number' => 'PR-000803', 'updated_at' => now()->subDays(5)]);
        PrintingOrder::factory()->forCustomer($this->customer)->status(PrintingOrderStatus::Cancelled)->create(['reference_number' => 'PR-000804', 'updated_at' => now()->subDays(9)]);

        $records = app(CustomerContextBuilder::class)->build($this->customer);

        $this->assertLessThanOrEqual(900, mb_strlen($records->block()));
        $this->assertStringContainsString('PR-000801', $records->block());
        $this->assertStringNotContainsString('PR-000804', $records->block(), 'The oldest finished went first');
        $this->assertSame(array_map(fn ($record) => $record->reference, $records->records), array_values(array_filter(
            ['PR-000801', 'PR-000802', 'PR-000803', 'PR-000804'], fn ($reference) => str_contains($records->block(), $reference))));
    }

    public function test_a_customer_without_records_gets_a_short_none_block(): void
    {
        $system = $this->block();

        $this->assertStringContainsString(CustomerRecords::HEADING, $system);
        $this->assertStringContainsString('None: this customer has no printing orders or photoshoot bookings yet.', $system);
        $this->assertStringContainsString('RULES FOR THESE RECORDS', $system);
    }

    public function test_the_block_sits_after_the_shop_knowledge_and_before_the_last_line_with_its_rules(): void
    {
        PrintingOrder::factory()->forCustomer($this->customer)->create(['reference_number' => 'PR-000901']);

        foreach ([AssistantFeature::AskLumi, AssistantFeature::StudioAway] as $feature) {
            $system = $this->block(feature: $feature);

            $knowledge = strpos($system, 'SHOP KNOWLEDGE');
            $records = strpos($system, CustomerRecords::HEADING);
            $latest = strpos($system, 'ANSWER ONLY THE LATEST MESSAGE');
            $this->assertTrue($knowledge < $records && $records < $latest, $feature->value);
            $this->assertStringEndsWith(PromptBuilder::LATEST_ONLY, $system);

            foreach (['They are the ONLY source for this customer\'s own orders', 'Never say or imply a status, date, time, amount or payment that the records do not show',
                'You cannot change, cancel, reschedule or confirm anything, and you cannot record a payment', 'There is no partial payment and no downpayment',
                'ask ONE short question that names the options', 'These records belong to this customer only'] as $rule) {
                $this->assertStringContainsString($rule, $system);
            }

            // Never in the user role
            foreach ($this->fake->lastRequest()->messages as $message) {
                $this->assertStringNotContainsString('PR-000901', $message->text);
            }
        }
    }

    public function test_guests_the_owner_assist_and_the_owner_get_no_records(): void
    {
        PrintingOrder::factory()->forCustomer($this->customer)->create(['reference_number' => 'PR-001001']);
        $owner = User::factory()->owner()->create();

        $this->ask('Where is my order?');
        $this->assertStringNotContainsString(CustomerRecords::HEADING, $this->fake->lastRequest()->systemInstruction, 'A guest');

        foreach ([AssistantFeature::OwnerAssistSuggest, AssistantFeature::OwnerAssistSummarize] as $feature) {
            $this->ask('Where is my order PR-001001?', $feature, AssistantActor::user($owner->id));
            $this->assertStringNotContainsString(CustomerRecords::HEADING, $this->fake->lastRequest()->systemInstruction, $feature->value);
        }

        // Even with a customer's id, owner assist never reads records
        $this->assertNull(app(CustomerContextBuilder::class)->for(new AssistantInput(AssistantFeature::OwnerAssistSuggest, AssistantActor::user($this->customer->id), 'x'))->block());

        $this->ask('Where is my order?', actor: AssistantActor::user($owner->id));
        $this->assertStringNotContainsString(CustomerRecords::HEADING, $this->fake->lastRequest()->systemInstruction, 'An owner in Ask Lumi');
    }

    public function test_the_query_count_does_not_grow_with_the_records(): void
    {
        $count = function () {
            $customer = $this->customer->fresh();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(CustomerContextBuilder::class)->build($customer);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // One of each kind, open and finished, so every query and eager load already runs
        PrintingOrder::factory()->forCustomer($this->customer)->create();
        PrintingOrder::factory()->forCustomer($this->customer)->status(PrintingOrderStatus::Cancelled)->create();
        PhotographyBooking::factory()->forCustomer($this->customer)->create();
        PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Cancelled)->create();
        $few = $count();

        foreach (range(1, 6) as $n) {
            $order = PrintingOrder::factory()->forCustomer($this->customer)->status($n % 2 ? PrintingOrderStatus::Accepted : PrintingOrderStatus::Completed)->create(['confirmed_price' => '100.00']);
            Payment::factory()->paying($order)->create();
            PhotographyBooking::factory()->forCustomer($this->customer)->status($n % 2 ? BookingStatus::Confirmed : BookingStatus::Completed)->create(['confirmed_price' => '900.00']);
        }

        $this->assertSame($few, $count());
        $this->assertSame(8, $few, 'Two queries per kind, each with its payments');
    }
}
