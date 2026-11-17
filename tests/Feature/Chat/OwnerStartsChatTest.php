<?php

namespace Tests\Feature\Chat;

use App\Enums\BookingStatus;
use App\Enums\OwnerPresenceMode;
use App\Enums\PrintingOrderStatus;
use App\Models\Conversation;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\CustomerContextBuilder;
use App\Services\Chat\LumiReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 10E: the owner writes first, and a message can be about one order or booking (one thread per customer still). */
class OwnerStartsChatTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

    public function test_the_owner_finds_customers_with_an_account_only(): void
    {
        $owner = $this->owner();
        $this->customer(['name' => 'Ana Santos', 'email' => 'ana@example.test', 'phone' => '09171230001']);
        $this->customer(['name' => 'Ana Unverified', 'email' => 'ana2@example.test', 'email_verified_at' => null]);
        User::factory()->walkIn()->create(['name' => 'Ana Walk-in', 'phone' => '09179990000']);

        $page = $this->actingAs($owner)->get(route('owner.messages.new', ['q' => 'ana']))->assertOk();
        $page->assertSee('Ana Santos')->assertSee('Ana Unverified')->assertSee('Email not verified yet')->assertDontSee('Ana Walk-in');

        $this->actingAs($owner)->get(route('owner.messages.new', ['q' => '0917 123']))->assertSee('Ana Santos');
        $this->actingAs($owner)->get(route('owner.messages.new', ['q' => '%']))->assertDontSee('Ana Santos');
    }

    public function test_starting_opens_the_one_thread_creating_it_once(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();

        $first = $this->actingAs($owner)->post(route('owner.messages.start'), ['customer' => $customer->id]);
        $thread = Conversation::query()->studio()->forCustomer($customer)->sole();
        $first->assertRedirect(route('owner.messages.show', $thread));

        // Again, or when the customer already has a thread: the same one, never a second
        $this->actingAs($owner)->post(route('owner.messages.start'), ['customer' => $customer->id])->assertRedirect(route('owner.messages.show', $thread));
        $this->assertSame(1, Conversation::query()->studio()->forCustomer($customer)->count());
        $this->actingAs($owner)->get(route('owner.messages.show', $thread))->assertOk();
    }

    public function test_walk_ins_and_owners_cannot_be_messaged_and_customers_cannot_start(): void
    {
        $owner = $this->owner();
        $walkIn = User::factory()->walkIn()->create(['phone' => '09171112222']);

        $this->actingAs($owner)->from(route('owner.customers.show', $walkIn))->post(route('owner.messages.start'), ['customer' => $walkIn->id])
            ->assertRedirect()->assertSessionHas('error', 'Walk-in customers have no account to message. Call 09171112222.');
        $this->actingAs($owner)->post(route('owner.messages.start'), ['customer' => $owner->id])->assertNotFound();
        $this->assertSame(0, Conversation::query()->count());

        $this->actingAs($owner)->get(route('owner.customers.show', $walkIn))->assertOk()->assertSee('Walk-in customers have no account to message.');

        $customer = $this->customer();
        $this->actingAs($customer)->post(route('owner.messages.start'), ['customer' => $customer->id])->assertForbidden();
    }

    public function test_an_owner_started_message_notifies_the_customer_and_keeps_lumi_quiet(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $this->presence(OwnerPresenceMode::Auto);

        $this->actingAs($owner)->post(route('owner.messages.start'), ['customer' => $customer->id]);
        $thread = Conversation::query()->studio()->forCustomer($customer)->sole();
        $this->actingAs($owner)->postJson(route('owner.messages.store', $thread), ['body' => 'Hi Ana, your prints are ready.', 'client_id' => 'o-1'])->assertCreated();

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $customer->id)->count());
        $this->actingAs($customer)->getJson(route('customer.messages.studio'))->assertOk()
            ->assertJsonPath('messages.0.body', 'Hi Ana, your prints are ready.')->assertJsonPath('state.studio.owner_away', false);

        // The customer answers right away: the owner is present, so Lumi does not answer for the studio
        $this->actingAs($customer)->postJson(route('customer.messages.store', $thread), ['body' => 'Thank you!', 'client_id' => 'c-1'])->assertCreated();
        $this->assertNotSame('replied', app(LumiReplyService::class)->reply($thread->fresh(), $customer)->status);
        $this->assertSame(0, $thread->messages()->where('sender_type', 'assistant')->count());
    }

    public function test_the_owner_attaches_only_that_customers_records(): void
    {
        $owner = $this->owner();
        $ana = $this->customer();
        $ben = $this->customer();
        $anaBooking = PhotographyBooking::factory()->forCustomer($ana)->status(BookingStatus::Requested)->create();
        $benOrder = PrintingOrder::factory()->forCustomer($ben)->status(PrintingOrderStatus::Submitted)->create();
        $thread = $this->studio($ana);

        // "Message customer about this" on another customer's record finds nothing
        $this->actingAs($owner)->post(route('owner.messages.start'), ['customer' => $ana->id, 'about_type' => 'printing_order', 'about_id' => $benOrder->id])->assertNotFound();

        $this->actingAs($owner)->post(route('owner.messages.start'), ['customer' => $ana->id, 'about_type' => 'photography_booking', 'about_id' => $anaBooking->id])
            ->assertRedirect(route('owner.messages.show', [$thread, 'about' => 'photography_booking:'.$anaBooking->id]));
        $this->actingAs($owner)->get(route('owner.messages.show', [$thread, 'about' => 'photography_booking:'.$anaBooking->id]))->assertOk()
            ->assertSee($anaBooking->reference_number)->assertDontSee($benOrder->reference_number);

        $this->actingAs($owner)->postJson(route('owner.messages.store', $thread), ['body' => 'About Ben', 'client_id' => 'o-2', 'about' => ['type' => 'printing_order', 'id' => $benOrder->id]])->assertStatus(422);
        $sent = $this->actingAs($owner)->postJson(route('owner.messages.store', $thread), ['body' => 'Your time is held.', 'client_id' => 'o-3', 'about' => ['type' => 'photography_booking', 'id' => $anaBooking->id]])->assertCreated();

        $sent->assertJsonPath('message.about.reference', $anaBooking->reference_number)->assertJsonPath('message.about.status', 'Requested');
        $this->assertStringStartsWith($anaBooking->reference_number.' - Your time is held.', $thread->fresh()->last_message_preview);
    }

    public function test_a_customer_cannot_reference_another_customers_record(): void
    {
        $ana = $this->customer();
        $ben = $this->customer();
        $benOrder = PrintingOrder::factory()->forCustomer($ben)->status(PrintingOrderStatus::Submitted)->create();
        $thread = $this->studio($ana);

        $this->actingAs($ana)->postJson(route('customer.messages.store', $thread), ['body' => 'Hi', 'client_id' => 'c-9', 'about' => ['type' => 'printing_order', 'id' => $benOrder->id]])->assertStatus(422);
        $this->assertSame(0, $thread->messages()->count());
    }

    public function test_lumi_puts_the_record_asked_about_first(): void
    {
        $ana = $this->customer();
        $older = PhotographyBooking::factory()->forCustomer($ana)->status(BookingStatus::Requested)->create();
        PrintingOrder::factory()->count(3)->forCustomer($ana)->status(PrintingOrderStatus::Submitted)->create();
        $ben = $this->customer();
        $benBooking = PhotographyBooking::factory()->forCustomer($ben)->status(BookingStatus::Requested)->create();

        $records = app(CustomerContextBuilder::class)->build($ana, ['type' => 'photography_booking', 'id' => $older->id]);
        $this->assertSame($older->reference_number, $records->records[0]->reference);
        $this->assertCount(1, array_filter($records->records, fn ($r) => $r->reference === $older->reference_number));

        // Another customer's record is never added
        $foreign = app(CustomerContextBuilder::class)->build($ana, ['type' => 'photography_booking', 'id' => $benBooking->id]);
        $this->assertNotContains($benBooking->reference_number, array_map(fn ($r) => $r->reference, $foreign->records));
    }
}
