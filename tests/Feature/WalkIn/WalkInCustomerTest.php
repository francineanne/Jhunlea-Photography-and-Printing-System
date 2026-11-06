<?php

namespace Tests\Feature\WalkIn;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PrintingOrderStatus;
use App\Enums\RecordSource;
use App\Models\Conversation;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\BookingTransitionService;
use App\Services\PaymentService;
use App\Services\StatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 8A: walk-in customers have no login, are never notified, and are shown and filtered in the directory. */
class WalkInCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
    }

    public function test_a_walk_in_can_never_sign_in_or_reset_a_password(): void
    {
        $walkIn = User::factory()->walkIn()->create();
        $this->assertNull($walkIn->email);
        $this->assertNull($walkIn->password);
        $this->assertTrue($walkIn->isWalkIn());

        // Even a walk-in row that somehow had an email and a password is refused by the login and the reset
        $odd = User::factory()->create(['email' => 'odd@example.test']);
        $odd->forceFill(['is_walk_in' => true])->save();

        $this->post('/login', ['email' => 'odd@example.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/forgot-password', ['email' => 'odd@example.test']);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        // An ordinary customer still signs in
        $online = User::factory()->create(['email' => 'ana@example.test']);
        $this->post('/login', ['email' => 'ana@example.test', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($online);
    }

    public function test_is_walk_in_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'Sneaky', 'email' => 'sneaky@example.test', 'password' => 'Password1', 'is_walk_in' => true])->fresh();

        $this->assertFalse($user->isWalkIn());
    }

    public function test_walk_ins_are_never_notified_while_the_owner_works_on_their_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 02:00:00', 'UTC'));
        $walkIn = User::factory()->walkIn()->create();
        $order = PrintingOrder::factory()->walkIn()->forCustomer($walkIn)->status(PrintingOrderStatus::UnderReview)->create();
        $transitions = app(StatusTransitionService::class);

        $transitions->transition($order, PrintingOrderStatus::Accepted, $this->owner, null, '100.00');
        $transitions->changePrice($order->fresh(), $this->owner, '120.00');
        app(PaymentService::class)->record($order->fresh(), $this->owner, PaymentMethod::Cash);
        $transitions->transition($order->fresh(), PrintingOrderStatus::Processing, $this->owner);

        $booking = PhotographyBooking::factory()->walkIn()->forCustomer($walkIn)->status(BookingStatus::Requested)->at(Carbon::parse('2026-11-20 02:00:00', 'UTC'))->create();
        app(BookingTransitionService::class)->cancel($booking, $this->owner, 'Customer called.');

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $walkIn->id)->count());

        // An online customer in the same situation is still told
        $online = User::factory()->create();
        $other = PrintingOrder::factory()->forCustomer($online)->status(PrintingOrderStatus::UnderReview)->create();
        $transitions->transition($other, PrintingOrderStatus::Accepted, $this->owner, null, '100.00');
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $online->id)->count());
    }

    public function test_a_walk_in_never_appears_in_the_customer_chat(): void
    {
        $walkIn = User::factory()->walkIn()->create();

        // No login means no chat page, no conversation and no customer facts for the assistant
        $this->get('/my/assistant')->assertRedirect(route('login'));
        $this->assertSame(0, Conversation::query()->where('customer_id', $walkIn->id)->count());
        $this->post('/login', ['email' => '', 'password' => ''])->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_the_directory_shows_and_filters_walk_ins(): void
    {
        $walkIn = User::factory()->walkIn()->create(['name' => 'Lola Counter', 'phone' => '0917 555 0001']);
        User::factory()->create(['name' => 'Online Ana', 'email' => 'ana@example.test']);

        $this->actingAs($this->owner)->get(route('owner.customers.index'))->assertOk()
            ->assertSee('Lola Counter')->assertSee('Online Ana')->assertSee('Walk-in')->assertSee('Walk-in, no login');
        $this->actingAs($this->owner)->get(route('owner.customers.index', ['type' => 'walk_in']))->assertOk()
            ->assertSee('Lola Counter')->assertDontSee('Online Ana');
        $this->actingAs($this->owner)->get(route('owner.customers.index', ['type' => 'online']))->assertOk()
            ->assertDontSee('Lola Counter')->assertSee('Online Ana');
        $this->actingAs($this->owner)->get(route('owner.customers.index', ['type' => 'robots']))->assertSessionHasErrors('type');

        $this->actingAs($this->owner)->get(route('owner.customers.show', $walkIn))->assertOk()
            ->assertSee('Lola Counter')->assertSee('No email: a walk-in customer, with no login');
    }

    public function test_the_picker_finds_customers_by_name_or_phone(): void
    {
        User::factory()->walkIn()->create(['name' => 'Maria Santos', 'phone' => '0917 111 2222']);
        User::factory()->walkIn()->create(['name' => 'Maria Santos', 'phone' => '0918 333 4444']);
        User::factory()->owner()->create(['name' => 'Maria Owner']);

        $byName = $this->actingAs($this->owner)->getJson(route('owner.walk-in.customers', ['q' => 'maria']))->assertOk()->json('customers');
        $this->assertCount(2, $byName);
        $this->assertSame(['0918 333 4444', '0917 111 2222'], array_column($byName, 'phone'));
        $this->assertTrue($byName[0]['walk_in']);

        $this->assertCount(1, $this->actingAs($this->owner)->getJson(route('owner.walk-in.customers', ['q' => '333']))->json('customers'));
        $this->assertCount(0, $this->actingAs($this->owner)->getJson(route('owner.walk-in.customers', ['q' => '%']))->json('customers'));
        $this->actingAs($this->owner)->getJson(route('owner.walk-in.customers', ['q' => ['x']]))->assertStatus(422);
    }

    public function test_existing_rows_default_to_online(): void
    {
        $customer = User::factory()->create();
        $service = PrintingService::factory()->published()->create();
        DB::table('printing_orders')->insert([
            'reference_number' => 'PR-555555', 'customer_id' => $customer->id, 'printing_service_id' => $service->id,
            'snapshot' => '{}', 'quantity' => 1, 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(RecordSource::Online, PrintingOrder::query()->where('reference_number', 'PR-555555')->first()->source);
        $this->assertFalse(User::query()->find($customer->id)->isWalkIn());
    }
}
