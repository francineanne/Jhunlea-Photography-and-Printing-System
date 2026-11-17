<?php

namespace Tests\Feature\Polish;

use App\Enums\PrintingOrderStatus;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\StatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Phase 10D: the version stamp behind the live updates changes for a person's OWN records only. */
class LiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $ana;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-18 02:00:00', 'UTC'));
        $this->owner = User::factory()->owner()->create();
        $this->ana = User::factory()->create();
        $this->ben = User::factory()->create();
    }

    private function stamp(User $user): string
    {
        $path = $user->role->value === 'owner' ? '/owner/live' : '/my/live';

        return (string) $this->actingAs($user)->getJson($path)->assertOk()->assertJsonStructure(['stamp', 'unread', 'latest'])->json('stamp');
    }

    public function test_the_stamp_changes_for_the_customers_own_records_and_not_for_another_customers(): void
    {
        $order = PrintingOrder::factory()->forCustomer($this->ana)->status(PrintingOrderStatus::UnderReview)->create();
        $ana = $this->stamp($this->ana);
        $ben = $this->stamp($this->ben);

        // Nothing happened: the stamp is stable
        $this->assertSame($ana, $this->stamp($this->ana));

        Carbon::setTestNow(now()->addMinute());
        app(StatusTransitionService::class)->transition($order, PrintingOrderStatus::Accepted, $this->owner, null, '150.00');

        $this->assertNotSame($ana, $this->stamp($this->ana), 'Her own order changed');
        $this->assertSame($ben, $this->stamp($this->ben), 'Another customer sees no change');
    }

    public function test_a_new_booking_changes_only_its_own_customers_stamp_and_the_owners(): void
    {
        $ana = $this->stamp($this->ana);
        $ben = $this->stamp($this->ben);
        $owner = $this->stamp($this->owner);

        PhotographyBooking::factory()->forCustomer($this->ben)->create();

        $this->assertSame($ana, $this->stamp($this->ana));
        $this->assertNotSame($ben, $this->stamp($this->ben));
        $this->assertNotSame($owner, $this->stamp($this->owner));
    }

    public function test_the_answer_carries_the_unread_count_and_the_newest_notification_and_nothing_personal(): void
    {
        $order = PrintingOrder::factory()->forCustomer($this->ana)->status(PrintingOrderStatus::UnderReview)->create();
        app(StatusTransitionService::class)->transition($order, PrintingOrderStatus::Accepted, $this->owner, null, '150.00');

        $response = $this->actingAs($this->ana)->getJson('/my/live')->assertOk();

        $this->assertSame(1, $response->json('unread'));
        $this->assertNotEmpty($response->json('latest.message'));
        $this->assertStringStartsWith('/my/', (string) $response->json('latest.url'));
        $this->assertSame(['stamp', 'unread', 'latest'], array_keys($response->json()));
        $this->assertStringNotContainsString($this->ana->email, $response->getContent());
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_only_the_right_role_reaches_each_endpoint(): void
    {
        $this->getJson('/my/live')->assertUnauthorized();
        $this->getJson('/owner/live')->assertUnauthorized();
        $this->actingAs($this->ana)->getJson('/owner/live')->assertForbidden();
        $this->actingAs($this->owner)->getJson('/my/live')->assertForbidden();

        // An unverified customer is stopped like on every other customer feature
        $new = User::factory()->unverified()->create();
        $this->actingAs($new)->get('/my/live')->assertRedirect(route('verification.notice'));
    }

    public function test_the_live_pages_are_marked_and_the_form_pages_are_not(): void
    {
        $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertOk()->assertSee('data-live="page"', false)->assertSee('data-live="bell"', false);
        $this->actingAs($this->ana)->get(route('customer.account.show'))->assertOk()->assertDontSee('data-live="page"', false);
        $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertOk()->assertSee('data-live="page"', false);
        $this->actingAs($this->owner)->get(route('owner.settings.index'))->assertOk()->assertDontSee('data-live="page"', false);
    }
}
