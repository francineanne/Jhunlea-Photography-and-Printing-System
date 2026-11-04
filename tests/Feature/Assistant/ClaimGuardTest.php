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
use App\Services\Assistant\ClaimGuard;
use App\Services\Assistant\CustomerContextBuilder;
use App\Services\Assistant\CustomerRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * Lumi never claims to have done something, and never states a status or payment the customer's records do not show
 * (Phase 7E-1), in English, Filipino and Taglish.
 */
class ClaimGuardTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    private function guard(): ClaimGuard
    {
        return app(ClaimGuard::class);
    }

    public function test_claims_of_its_own_actions_are_always_removed(): void
    {
        $customer = User::factory()->create();
        PhotographyBooking::factory()->forCustomer($customer)->status(BookingStatus::Confirmed)->create();
        $records = app(CustomerContextBuilder::class)->build($customer);

        foreach ([
            'I booked the 10 AM slot for you.',
            'I have confirmed your booking.',
            "I've cancelled your order.",
            'I rescheduled your shoot to Saturday.',
            'I have already received your payment.',
            'We recorded your payment of the full price.',
            'Na-cancel ko na po ang order ninyo.',
            'Na-confirm ko na ang booking mo.',
            'Natanggap ko na ang bayad mo.',
            'Kinansela ko na po ang booking.',
            'Ni-reschedule ko na po sa Sabado.',
        ] as $claim) {
            $this->assertTrue($this->guard()->claims($claim, $records), $claim);
            $this->assertSame(config('assistant.messages.claim_unverified'), $this->guard()->guard($claim, $records), $claim);
        }
    }

    public function test_a_status_or_payment_fact_needs_a_record_that_shows_it(): void
    {
        $facts = [
            'ready' => ['Your order is ready for pickup.', 'Ready na po ang prints ninyo.', 'Pwede mo nang kunin ang order mo.'],
            'confirmed' => ['Your booking is confirmed.', 'Confirmed na po ang booking ninyo.'],
            'completed' => ['Your order has been completed.', 'Tapos na po ang order ninyo.'],
            'paid' => ['Your order is fully paid.', 'You have already paid.', 'Bayad na po kayo.', 'Your booking is already paid.'],
        ];

        $none = app(CustomerContextBuilder::class)->build(User::factory()->create());

        foreach ($facts as $sentences) {
            foreach ($sentences as $sentence) {
                $this->assertTrue($this->guard()->claims($sentence, $none), "Without a record: {$sentence}");
                $this->assertTrue($this->guard()->claims($sentence, CustomerRecords::none()), "A guest: {$sentence}");
            }
        }

        $customer = User::factory()->create();
        $ready = PrintingOrder::factory()->forCustomer($customer)->status(PrintingOrderStatus::ReadyForPickup)->create(['confirmed_price' => '120.00']);
        Payment::factory()->paying($ready)->create();
        PhotographyBooking::factory()->forCustomer($customer)->status(BookingStatus::Completed)->create(['confirmed_price' => '900.00']);
        $records = app(CustomerContextBuilder::class)->build($customer);

        foreach ($facts as $state => $sentences) {
            $this->assertTrue($records->shows($state), $state);

            foreach ($sentences as $sentence) {
                $this->assertFalse($this->guard()->claims($sentence, $records), "With a {$state} record: {$sentence}");
            }
        }
    }

    public function test_questions_conditions_and_how_things_work_are_not_claims(): void
    {
        $none = CustomerRecords::none();

        foreach ([
            'Is your order ready for pickup?',
            'We will message you once it is ready for pickup.',
            'When your booking is confirmed, you will get a notification.',
            'Kapag ready na, makakatanggap ka ng notification.',
            'Kung confirmed na ang booking, makikita mo ito sa My bookings.',
            'The final price is confirmed by the owner after review.',
            'The full price is paid at the shop, in cash or GCash.',
            'You can cancel it on the booking page.',
            'The studio will confirm your booking.',
            'Your order is not ready yet.',
            'I can help you check your order status.',
        ] as $sentence) {
            $this->assertFalse($this->guard()->claims($sentence, $none), $sentence);
        }
    }

    public function test_only_the_claiming_sentences_go_and_price_guard_still_runs(): void
    {
        $customer = User::factory()->create();
        PrintingOrder::factory()->forCustomer($customer)->create(['reference_number' => 'PR-000777']);

        $this->fake->push(self::reply('answer', "Your order PR-000777 is still Submitted. It is ready for pickup now. I confirmed it for you.\nThe rush fee is ₱5,000. You can check My orders anytime."));
        $result = $this->ask('Status ng order ko?', actor: AssistantActor::user($customer->id));

        $this->assertSame("Your order PR-000777 is still Submitted.\nYou can check My orders anytime.", $result->reply);
    }

    public function test_the_owner_assist_draft_is_not_guarded(): void
    {
        $owner = User::factory()->owner()->create();
        $this->fake->push(self::reply('answer', 'Hi! I have confirmed your booking for Saturday.'));

        $result = $this->ask('Can you confirm my booking?', AssistantFeature::OwnerAssistSuggest, AssistantActor::user($owner->id));

        $this->assertSame('Hi! I have confirmed your booking for Saturday.', $result->reply, 'The owner writes as the studio and edits before sending');
    }
}
