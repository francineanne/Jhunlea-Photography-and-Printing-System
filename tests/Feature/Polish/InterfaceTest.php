<?php

namespace Tests\Feature\Polish;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\Faq;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\KnowledgeBuilder;
use App\Services\Chat\OwnerPresence;
use App\Support\NextSteps;
use App\Support\PaymentRules;
use App\Support\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 10D: the final polish, checked in what the server renders (so it also holds with JavaScript off). */
class InterfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-18 02:00:00', 'UTC'));
        $this->owner = User::factory()->owner()->create();
        $this->ana = User::factory()->create();
    }

    private function settings(array $values): void
    {
        DB::table('shop_settings')->updateOrInsert(['id' => 1], $values + ['created_at' => now(), 'updated_at' => now()]);
        app(ShopSettings::class)->forget();
    }

    public function test_the_policy_line_is_one_short_sentence_from_the_payment_rules(): void
    {
        $this->assertSame('No online payment. Pay at the shop or by GCash; photoshoots need a 50% downpayment.', app(PaymentRules::class)->shortLine());

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-policy-bar'));
        $this->assertStringContainsString('photoshoots need a 50% downpayment.', $html);
        // The old three-line strip and the hero callout are gone; the standard sentence stays once, in the footer
        $this->assertStringNotContainsString('Studio policy:', $html);
        $this->assertSame(1, substr_count($html, 'Payment is made face-to-face at Jhunlea Photography &amp; Printing.'));

        $this->settings(['downpayment_percent' => 0]);
        $this->assertSame('No online payment. Pay at the shop in cash or by GCash.', app(PaymentRules::class)->shortLine());
        $this->get('/services')->assertOk()->assertSee('No online payment. Pay at the shop in cash or by GCash.');
    }

    public function test_a_top_navigation_item_never_wraps_and_the_active_one_only_changes_colour(): void
    {
        $html = $this->get('/packages')->assertOk()->getContent();

        preg_match_all('/<a href="[^"]+"[^>]*class="nav-item [^"]*"/', $html, $links);
        $top = array_values(array_filter($links[0], fn ($a) => str_contains($a, 'whitespace-nowrap')));
        $this->assertNotEmpty($top);

        foreach ($top as $link) {
            // The same padding and weight whether active or not, so nothing moves when the page changes
            $this->assertStringContainsString('px-3', $link);
            $this->assertStringContainsString('font-semibold', $link);
            $this->assertStringNotContainsString('shadow-sm', $link);
        }
    }

    public function test_the_time_picker_is_a_plain_select_of_times_without_javascript(): void
    {
        $html = $this->actingAs($this->owner)->get(route('owner.schedule.edit'))->assertOk()->getContent();

        // No native scrolling time wheel is left on the page
        $this->assertStringNotContainsString('type="time"', $html);
        $this->assertStringContainsString('name="hours[1][opens]"', $html);
        $this->assertMatchesRegularExpression('/<option value="09:00"[^>]*>9:00 AM<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="17:30"[^>]*>5:30 PM<\/option>/', $html);
        $this->assertStringContainsString('data-time-picker', $html);

        // The server still takes the same H:i value
        $hours = [];
        foreach (range(0, 6) as $day) {
            $hours[$day] = ['open' => '1', 'opens' => '10:00', 'closes' => '16:30'];
        }
        $this->actingAs($this->owner)->post(route('owner.schedule.hours'), ['hours' => $hours])->assertSessionHasNoErrors();
        $this->assertSame('10:00', substr((string) DB::table('availability_rules')->where('weekday', 1)->value('opens_at'), 0, 5));
    }

    public function test_important_forms_ask_first_in_the_one_dialog_and_still_post_without_scripts(): void
    {
        $booking = PhotographyBooking::factory()->forCustomer($this->ana)->status(BookingStatus::Requested)->at(now()->addDays(5))->create();
        $booking->forceFill(['hold_expires_at' => now()->addDay()])->save();

        $owner = $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->assertOk()->getContent();
        $this->assertStringContainsString('data-confirm-dialog', $owner);
        $this->assertStringContainsString('data-confirm="Confirm booking '.$booking->reference_number.'?"', $owner);
        $this->assertStringContainsString('A 50% downpayment of the confirmed price is asked for, due by', $owner);
        $this->assertStringContainsString('data-confirm="Reject booking '.$booking->reference_number.'?"', $owner);
        $this->assertStringNotContainsString('confirm(', $owner);

        $customer = $this->actingAs($this->ana)->get(route('customer.bookings.show', $booking))->assertOk()->getContent();
        $this->assertStringContainsString('data-confirm="Cancel booking '.$booking->reference_number.'?"', $customer);
        $this->assertStringContainsString('data-confirm-danger', $customer);

        // Without JavaScript nothing intercepts: the plain post is still decided by the server
        $this->actingAs($this->ana)->post(route('customer.bookings.cancel', $booking))->assertRedirect();
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_the_next_steps_follow_the_real_status(): void
    {
        $booking = PhotographyBooking::factory()->forCustomer($this->ana)->status(BookingStatus::AwaitingDownpayment)->at(now()->addDays(6))->create();
        $booking->forceFill(['confirmed_price' => '2000.00', 'downpayment_due_at' => now()->addHours(20)])->save();

        $steps = app(NextSteps::class)->forBooking($booking->fresh());
        $this->assertNull($steps['closed']);
        $this->assertSame(['Request sent', 'Studio reviews', 'Pay the downpayment', 'Shoot day', 'Photos released'], array_column($steps['steps'], 'label'));
        $this->assertSame(['done', 'done', 'current', 'upcoming', 'upcoming'], array_column($steps['steps'], 'state'));

        $page = $this->actingAs($this->ana)->get(route('customer.bookings.show', $booking))->assertOk();
        $page->assertSee('data-next-steps', false)->assertSee('aria-current="step"', false)->assertSee('Due in 20 hours 0 min');

        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();
        $this->assertSame('This booking was cancelled, so nothing more happens here.', app(NextSteps::class)->forBooking($booking->fresh())['closed']);

        $order = PrintingOrder::factory()->forCustomer($this->ana)->status(PrintingOrderStatus::ReadyForPickup)->create();
        $order->forceFill(['confirmed_price' => '90.00'])->save();
        $states = array_column(app(NextSteps::class)->forOrder($order->fresh())['steps'], 'state', 'label');
        $this->assertSame('current', $states['Ready for pickup']);
        $this->assertSame('done', $states['Printing']);
        $this->actingAs($this->ana)->get(route('customer.printing.show', $order))->assertOk()->assertSee('Where your order stands');
    }

    public function test_the_landing_page_shows_the_first_six_questions_and_visit_us_only_when_set(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Visit us')->assertDontSee('Questions customers ask');

        foreach (range(1, 8) as $i) {
            Faq::factory()->create(['question' => "Question number {$i}?", 'answer' => "Answer {$i}."]);
        }
        Faq::factory()->hidden()->create(['question' => 'A hidden question?']);
        $this->settings(['address' => '12 Sample Street, Sample City', 'opening_hours' => 'Mon-Sat 9:00 AM - 5:00 PM', 'facebook_url' => 'https://www.facebook.com/jhunlea.sample']);

        $page = $this->get('/')->assertOk();
        $page->assertSee('Questions customers ask')->assertSee('Question number 6?')->assertDontSee('Question number 7?')->assertDontSee('A hidden question?')
            ->assertSee('See all questions')
            ->assertSee('Visit us')->assertSee('12 Sample Street, Sample City')->assertSee('Mon-Sat 9:00 AM - 5:00 PM')
            ->assertSee('https://www.facebook.com/jhunlea.sample', false)
            ->assertSee('https://www.google.com/maps?q=12%20Sample%20Street%2C%20Sample%20City&amp;output=embed', false)
            ->assertSee('loading="lazy"', false)->assertSee('Open in Google Maps');
    }

    public function test_the_facebook_address_must_be_an_https_facebook_page(): void
    {
        $save = fn (string $url) => $this->actingAs($this->owner)->post(route('owner.settings.business'), ['shop_name' => 'Jhunlea', 'facebook_url' => $url]);

        foreach (['http://www.facebook.com/page', 'https://example.com/facebook.com/page', 'https://facebook.com.evil.test/page', 'javascript:alert(1)', 'https://www.facebook.com/'] as $bad) {
            $save($bad)->assertSessionHasErrors('facebook_url');
        }
        $this->assertNull(DB::table('shop_settings')->where('id', 1)->value('facebook_url'));

        foreach (['https://www.facebook.com/jhunlea.sample', 'https://fb.com/jhunlea', 'https://m.facebook.com/profile.php?id=100012345'] as $good) {
            $save($good)->assertSessionHasNoErrors();
            $this->assertSame($good, DB::table('shop_settings')->where('id', 1)->value('facebook_url'));
        }

        // Lumi's knowledge and the public footer read the same setting
        $this->get('/services')->assertOk()->assertSee('https://m.facebook.com/profile.php?id=100012345', false);
        $this->assertStringContainsString('Facebook page: https://m.facebook.com/profile.php?id=100012345', app(KnowledgeBuilder::class)->stable());
    }

    public function test_the_calendar_marks_requests_that_hold_their_time(): void
    {
        $held = PhotographyBooking::factory()->forCustomer($this->ana)->status(BookingStatus::Requested)->at(now()->addDays(3))->create();
        $held->forceFill(['hold_expires_at' => now()->addDay()])->save();
        $loose = PhotographyBooking::factory()->forCustomer($this->ana)->status(BookingStatus::Requested)->at(now()->addDays(4))->create();
        $loose->forceFill(['hold_expires_at' => now()->subHour()])->save();

        $html = $this->actingAs($this->owner)->get(route('owner.schedule.index', ['view' => 'month', 'date' => now()->addDays(3)->format('Y-m-d')]))->assertOk()->getContent();

        $this->assertStringContainsString($held->reference_number.' · Held request (holds this time)', $html);
        $this->assertStringContainsString($loose->reference_number.' · Requested"', $html);
        $this->assertStringContainsString('Held request (holds its time)', $html);
        $this->assertStringNotContainsString('Only confirmed sessions hold a time.', $html);
    }

    public function test_the_away_notice_gives_the_shop_hours_text_as_written(): void
    {
        config(['assistant.enabled' => true]);
        $this->settings(['opening_hours' => 'Mon-Sat 9:00 AM - 5:00 PM; Sun closed']);

        $notice = app(OwnerPresence::class)->awayNotice();

        $this->assertStringContainsString('Shop hours: Mon-Sat 9:00 AM - 5:00 PM; Sun closed.', $notice);
        // "Open now" is never worked out from the owner's text
        $this->assertStringNotContainsString('The studio is open', $notice);
        $this->assertStringNotContainsString('will be back', $notice);
    }

    public function test_cards_use_the_one_price_and_a_designed_placeholder(): void
    {
        PhotographyPackage::factory()->published()->featured()->create(['name' => 'No Photo Package', 'category' => 'Family', 'price' => '2500.00', 'price_requires_confirmation' => true]);

        foreach (['/', '/packages'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-image-placeholder', $html, $url);
            $this->assertStringContainsString('family_restroom', $html, $url);
            $this->assertStringContainsString('data-price', $html, $url);
            $this->assertStringContainsString('From ₱2,500.00', $html, $url);
            $this->assertStringContainsString('Final price confirmed after review', $html, $url);
        }

        $wizard = $this->actingAs($this->ana)->get(route('customer.bookings.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-image-placeholder', $wizard);
        $this->assertStringContainsString('data-price', $wizard);
    }

    public function test_the_filters_stay_on_the_page_without_javascript_and_prefetch_skips_side_effects(): void
    {
        PhotographyPackage::factory()->published()->create();

        $html = $this->get('/packages')->assertOk()->getContent();
        $this->assertStringContainsString('class="filter-sheet"', $html);
        $this->assertStringContainsString('name="budget"', $html);

        $this->assertStringContainsString('<script type="speculationrules">', $html);
        foreach (['/logout*', '/email/verify/*', '/files/*', '/booking-*', '/*/csv*', '/*/receipt*', '/*/notifications/*', '/owner/messages/*'] as $excluded) {
            $this->assertStringContainsString('{"not":{"href_matches":"'.$excluded.'"}}', $html, $excluded);
        }
        $this->assertStringContainsString('"eagerness":"moderate"', $html);
    }
}
