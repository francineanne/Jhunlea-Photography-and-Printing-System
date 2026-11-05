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

        // Phase 7E-2a: the claim must be true of the record it is about, so each state gets a customer whose records
        // all show it (before, any record of the customer was enough)
        $records = [
            'ready' => function (User $customer) {
                PrintingOrder::factory()->forCustomer($customer)->status(PrintingOrderStatus::ReadyForPickup)->create(['confirmed_price' => '120.00']);
            },
            'confirmed' => function (User $customer) {
                PhotographyBooking::factory()->forCustomer($customer)->status(BookingStatus::Confirmed)->create(['confirmed_price' => '900.00']);
            },
            'completed' => function (User $customer) {
                $order = PrintingOrder::factory()->forCustomer($customer)->status(PrintingOrderStatus::Completed)->create(['confirmed_price' => '80.00']);
                Payment::factory()->paying($order)->create();
            },
            'paid' => function (User $customer) {
                $order = PrintingOrder::factory()->forCustomer($customer)->status(PrintingOrderStatus::ReadyForPickup)->create(['confirmed_price' => '120.00']);
                Payment::factory()->paying($order)->create();
                $booking = PhotographyBooking::factory()->forCustomer($customer)->status(BookingStatus::Confirmed)->create(['confirmed_price' => '900.00']);
                Payment::factory()->paying($booking)->create();
            },
        ];

        foreach ($facts as $state => $sentences) {
            $customer = User::factory()->create();
            $records[$state]($customer);
            $built = app(CustomerContextBuilder::class)->build($customer);
            $this->assertTrue($built->shows($state), $state);

            foreach ($sentences as $sentence) {
                $this->assertFalse($this->guard()->claims($sentence, $built), "With a {$state} record: {$sentence}");
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
            // "The studio will confirm your booking." was here until 7E-2c: it is now a promise (see below)
            'The studio will review your booking.',
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

    /** A ready order PR-000001 ("Photo Printing") and an unconfirmed booking PB-000001 ("Family Portrait"). */
    private function readyOrderAndUnreadyBooking(): CustomerRecords
    {
        $customer = User::factory()->create();
        PrintingOrder::factory()->forCustomer($customer)->status(PrintingOrderStatus::ReadyForPickup)->create([
            'reference_number' => 'PR-000001', 'confirmed_price' => '150.00',
            'snapshot' => ['service' => ['name' => 'Photo Printing', 'pricing_type' => 'per_copy', 'base_price' => '5.00'], 'options' => [], 'pages' => 1, 'unit_price' => '5.00'],
        ]);
        PhotographyBooking::factory()->forCustomer($customer)->status(BookingStatus::Requested)->create([
            'reference_number' => 'PB-000001',
            'snapshot' => ['package' => ['name' => 'Family Portrait', 'duration_minutes' => 60, 'price_case' => 'fixed', 'price_text' => '₱3,500.00', 'inclusions' => []], 'theme' => null],
        ]);

        return app(CustomerContextBuilder::class)->build($customer);
    }

    public function test_a_claim_is_checked_against_the_record_it_names(): void
    {
        $records = $this->readyOrderAndUnreadyBooking();

        foreach ([
            'PR-000001 is ready for pickup.',
            'Your order pr000001 is ready for pickup.',
            'Ready na po ang PR 000001 ninyo.',
            'Your Photo Printing order is ready for pickup.',
            'Ready na ang order mo.',
            'Your order is ready for pickup.',
            'PR-000001 is ready for pickup, and PB-000001 is still waiting for the studio.',
        ] as $kept) {
            $this->assertFalse($this->guard()->claims($kept, $records), "Kept: {$kept}");
        }

        foreach ([
            'PB-000001 is ready.',
            'pb-000001 is ready for pickup.',
            'Your booking PB-000001 is confirmed.',
            'Your Family Portrait is ready.',
            'Your booking is confirmed.',
            'Your shoot is ready.',
            // Not one of this customer's records: never verified
            'PR-999999 is ready for pickup.',
            // Generic, while one record is ready and the other is not
            'It is ready for pickup.',
            'Ready na po.',
            'Everything is confirmed.',
        ] as $removed) {
            $this->assertTrue($this->guard()->claims($removed, $records), "Removed: {$removed}");
        }
    }

    public function test_a_generic_claim_needs_every_open_record_of_that_kind_in_that_state(): void
    {
        $customer = User::factory()->create();
        PrintingOrder::factory()->forCustomer($customer)->status(PrintingOrderStatus::ReadyForPickup)->create(['reference_number' => 'PR-000011', 'confirmed_price' => '90.00']);
        PrintingOrder::factory()->forCustomer($customer)->status(PrintingOrderStatus::Processing)->create(['reference_number' => 'PR-000012', 'confirmed_price' => '90.00']);
        $records = app(CustomerContextBuilder::class)->build($customer);

        $this->assertTrue($this->guard()->claims('Your order is ready for pickup.', $records), 'Two orders, only one ready');
        $this->assertFalse($this->guard()->claims('PR-000011 is ready for pickup.', $records));
        $this->assertTrue($this->guard()->claims('PR-000012 is ready for pickup.', $records));
        $this->assertFalse($this->guard()->claims('Both of your orders are confirmed.', $records), 'Both are accepted or further on');
    }

    public function test_questions_and_conditions_about_a_named_record_are_still_not_claims(): void
    {
        $records = $this->readyOrderAndUnreadyBooking();

        foreach ([
            'Is PB-000001 ready?',
            'Ready na ba ang PB-000001?',
            'Once PB-000001 is confirmed, you will get a notification.',
            'Kapag confirmed na ang PB-000001, makikita mo ito sa My bookings.',
            'We are ready to help you with PB-000001.',
            'The studio is ready for your questions.',
            // Denials (found in the 7E-2a live QA: the per-record check removed a true "not ready")
            'No, PB-000001 is not ready for pickup yet.',
            'PB-000001 has not been confirmed yet.',
            'PB-000001 is not yet ready for pickup.',
            "Your booking isn't confirmed yet.",
            'Hindi pa po ready for pickup ang PB-000001.',
        ] as $sentence) {
            $this->assertFalse($this->guard()->claims($sentence, $records), $sentence);
        }
    }

    public function test_through_the_engine_only_the_true_record_claim_stays(): void
    {
        $customer = User::factory()->create();
        PrintingOrder::factory()->forCustomer($customer)->status(PrintingOrderStatus::ReadyForPickup)->create(['reference_number' => 'PR-000021', 'confirmed_price' => '150.00']);
        PhotographyBooking::factory()->forCustomer($customer)->status(BookingStatus::Requested)->create(['reference_number' => 'PB-000021']);

        $this->fake->push(self::reply('answer', 'PR-000021 is ready for pickup. PB-000021 is ready too. Message the studio for the schedule.'));
        $result = $this->ask('Ready na ba lahat?', actor: AssistantActor::user($customer->id));

        $this->assertSame('PR-000021 is ready for pickup. Message the studio for the schedule.', $result->reply);
    }

    public function test_up_to_two_adverbs_before_the_state_word_are_still_a_claim_for_every_state(): void
    {
        // Phase 7E-2c: the real eval found "is totally confirmed" slipping through
        $none = app(CustomerContextBuilder::class)->build(User::factory()->create());

        foreach ([
            'confirmed' => ['Your booking is totally confirmed.', 'Your booking is now really confirmed!', 'It has been officially confirmed.', 'Confirmed po na ang booking ninyo.', 'Confirmed na talaga ang booking mo.'],
            'ready' => ['Your order is definitely ready.', 'Your prints are now fully ready.', 'Ready talaga na po ang prints ninyo.'],
            'completed' => ['Your order is already officially completed.', 'Your session has been totally done.', 'Tapos po na ang order ninyo.'],
            'paid' => ['Your order is completely paid.', 'You have already fully paid.', 'Your booking is surely fully paid.', 'Bayad talaga na po kayo.'],
        ] as $state => $sentences) {
            foreach ($sentences as $sentence) {
                $this->assertTrue($this->guard()->claims($sentence, $none), "{$state}: {$sentence}");
            }
        }

        // With a record in that state the same sentences stay
        $customer = User::factory()->create();
        PhotographyBooking::factory()->forCustomer($customer)->status(BookingStatus::Confirmed)->create(['confirmed_price' => '900.00']);
        $this->assertFalse($this->guard()->claims('Your booking is totally confirmed.', app(CustomerContextBuilder::class)->build($customer)));
    }

    public function test_adverbs_keep_the_question_condition_and_negation_exemptions(): void
    {
        $none = CustomerRecords::none();

        foreach ([
            'Is your booking totally confirmed?',
            'Once it is officially confirmed, you will get a notification.',
            'Your order is not totally ready yet.',
            'Your booking has not been officially confirmed.',
            'A booking is usually confirmed within a day.',
            'It is only ready after the shop reviews it.',
            'The full price is usually paid at the shop.',
        ] as $sentence) {
            $this->assertFalse($this->guard()->claims($sentence, $none), $sentence);
        }
    }

    public function test_a_promise_that_the_owner_or_studio_will_confirm_approve_or_accept_is_removed(): void
    {
        $none = CustomerRecords::none();

        foreach ([
            'The owner will confirm it there.',
            'The studio will confirm your booking.',
            'The studio will approve your booking.',
            'They will accept your request.',
            "We'll confirm it for Saturday.",
            'The owner is going to confirm your booking.',
            'The owner will definitely approve it.',
            'Your booking will be confirmed by the owner.',
            'Your request will be approved soon.',
            'Kukumpirmahin ng owner ang booking ninyo.',
            'I-a-approve nila ang booking mo.',
            'Tatanggapin nila ang request mo.',
        ] as $sentence) {
            $this->assertTrue($this->guard()->claims($sentence, $none), $sentence);
        }
    }

    public function test_what_is_not_a_promise_stays(): void
    {
        $none = CustomerRecords::none();

        foreach ([
            'The owner confirms the final price after review.',
            'The studio will confirm the price after reviewing your files.',
            'The studio will confirm the final price.',
            'The final price will be confirmed by the owner.',
            'Kukumpirmahin ng owner ang presyo.',
            'The shop will accept cash or GCash.',
            'The studio will reply to you soon.',
            'The owner will message you here.',
            'The studio will review your request and reply.',
            'Will the owner approve it?',
            'If the owner will confirm it, you will get a notification.',
            'The owner has not confirmed it yet.',
            'Hindi pa ito kukumpirmahin hangga\'t walang reply.',
        ] as $sentence) {
            $this->assertFalse($this->guard()->claims($sentence, $none), $sentence);
        }
    }

    public function test_the_real_studio_reply_from_the_eval_loses_only_its_promise(): void
    {
        $this->fake->push(self::reply('answer', 'I can\'t confirm bookings myself. Please wait for the studio to review your request in this thread. The owner will confirm it there.'));

        $result = $this->ask('Can the owner confirm my booking for Saturday 3pm?', AssistantFeature::StudioAway, AssistantActor::user(User::factory()->create()->id));

        $this->assertSame("I can't confirm bookings myself. Please wait for the studio to review your request in this thread.", $result->reply);
    }

    public function test_the_prompt_tells_lumi_not_to_promise_what_the_owner_will_do(): void
    {
        $this->ask('Will the owner approve it?');
        $this->assertStringContainsString('Never say or imply that the owner or the studio WILL confirm, approve or accept a booking or request', $this->fake->lastRequest()->stableSystem);

        $this->ask('Will the owner approve it?', AssistantFeature::StudioAway, AssistantActor::user(User::factory()->create()->id));
        $this->assertStringContainsString('never promise what the owner will do with them', $this->fake->lastRequest()->stableSystem);
    }

    public function test_the_owner_assist_draft_is_not_guarded(): void
    {
        $owner = User::factory()->owner()->create();
        $this->fake->push(self::reply('answer', 'Hi! I have confirmed your booking for Saturday.'));

        $result = $this->ask('Can you confirm my booking?', AssistantFeature::OwnerAssistSuggest, AssistantActor::user($owner->id));

        $this->assertSame('Hi! I have confirmed your booking for Saturday.', $result->reply, 'The owner writes as the studio and edits before sending');
    }
}
