<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\BlockedDate;
use App\Models\BookingReferenceImage;
use App\Models\BookingStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Phase 4B: the customer's booking wizard, its slot list, the request page and the ways in. */
class BookingWizardTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    /** Thursday 5 November 2026, 10:00 in Manila: a free slot in the test week */
    private const SLOT = '2026-11-05T02:00:00Z';

    private User $customer;

    private PhotographyPackage $package;

    private Theme $theme;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->setUpSchedule();

        $this->customer = User::factory()->create();
        $this->package = PhotographyPackage::factory()->published()->create([
            'name' => 'Graduation Portrait',
            'slug' => 'graduation-portrait',
            'price' => '1850.00',
            'price_requires_confirmation' => false,
            'duration_minutes' => 60,
            'max_people' => 3,
        ]);
        $this->theme = Theme::factory()->published()->create(['name' => 'Classic Crimson']);
        $this->package->themes()->attach($this->theme);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function jpeg(string $name = 'pose.jpg', int $padTo = 0): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        imagejpeg(imagecreatetruecolor(40, 30), $path);
        if ($padTo > 0) {
            file_put_contents($path, str_repeat("\0", max(0, $padTo - filesize($path))), FILE_APPEND);
        }
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function file(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'package' => $this->package->id,
            'group_size' => 2,
            'contact_number' => '0917 123 4567',
            'notes' => 'Bringing a toga.',
            'starts_at' => self::SLOT,
            'theme' => $this->theme->id,
            'custom_theme_text' => '',
            'prints' => 'maybe',
        ], $overrides);
    }

    private function send(array $overrides = [])
    {
        return $this->actingAs($this->customer)->from(route('customer.bookings.create'))->post(route('customer.bookings.store'), $this->payload($overrides));
    }

    private function assertNothingSaved(): void
    {
        $this->assertSame(0, PhotographyBooking::withTrashed()->count());
        $this->assertSame(0, BookingReferenceImage::count());
        $this->assertSame(0, BookingStatusHistory::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** The wizard step that is open when the page loads: the only section without data-js-hidden. */
    private function openStep(string $html): int
    {
        foreach (range(1, 6) as $n) {
            preg_match('/<section id="step-'.$n.'"[^>]*>/', $html, $tag);

            if (! str_contains($tag[0], 'data-js-hidden')) {
                return $n;
            }
        }

        $this->fail('No step is open.');
    }

    private function slots(array $query, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->customer)->getJson(route('customer.bookings.slots', $query + ['package' => $this->package->id]));
    }

    // Access

    public function test_guests_are_sent_to_login_and_the_owner_is_refused(): void
    {
        $booking = PhotographyBooking::factory()->forCustomer($this->customer)->create();

        $this->get(route('customer.bookings.create'))->assertRedirect(route('login'));
        $this->get(route('customer.bookings.slots', ['package' => $this->package->id, 'from' => '2026-11-05', 'to' => '2026-11-05']))->assertRedirect(route('login'));
        $this->post(route('customer.bookings.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('customer.bookings.sent', $booking))->assertRedirect(route('login'));

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->get(route('customer.bookings.create'))->assertForbidden();
        $this->actingAs($owner)->get(route('customer.bookings.slots', ['package' => $this->package->id, 'from' => '2026-11-05', 'to' => '2026-11-05']))->assertForbidden();
        $this->actingAs($owner)->post(route('customer.bookings.store'), $this->payload())->assertForbidden();
        $this->actingAs($owner)->get(route('customer.bookings.sent', $booking))->assertForbidden();
        $this->assertSame(1, PhotographyBooking::count());
    }

    public function test_a_guest_who_signs_in_comes_back_to_the_wizard_with_the_package(): void
    {
        $wizard = route('customer.bookings.create', ['package' => 'graduation-portrait']);

        $this->get($wizard)->assertRedirect(route('login'));
        $this->post('/login', ['email' => $this->customer->email, 'password' => 'password'])->assertRedirect($wizard);
    }

    public function test_after_login_the_owner_never_lands_on_a_customer_page(): void
    {
        $owner = User::factory()->owner()->create();

        $this->get(route('customer.bookings.create'))->assertRedirect(route('login'));
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect('/owner');
    }

    public function test_a_new_customer_who_registers_comes_back_to_the_wizard(): void
    {
        $wizard = route('customer.bookings.create', ['package' => 'graduation-portrait']);
        $this->get($wizard);

        $this->post('/register', [
            'name' => 'New Customer', 'email' => 'new@example.test', 'phone' => '09171234567',
            'password' => 'Secret-Pass-123', 'password_confirmation' => 'Secret-Pass-123',
        ])->assertRedirect($wizard);
    }

    // The way in

    public function test_every_public_page_links_to_the_wizard_with_the_package(): void
    {
        $this->package->update(['is_featured' => true]);
        $link = e(route('customer.bookings.create', ['package' => 'graduation-portrait']));

        $this->get('/')->assertSee($link, false);
        $this->get('/packages')->assertSee($link, false);
        $this->get('/packages/graduation-portrait')->assertSee($link, false)->assertSee('Book this package');
        $other = PhotographyPackage::factory()->published()->create(['slug' => 'other']);
        $this->get('/packages/compare?packages[]=graduation-portrait&packages[]=other')->assertSee($link, false);
        $this->get('/packages/finder?people=2')->assertSee($link, false);

        // The owner does not book
        $this->actingAs(User::factory()->owner()->create())->get('/packages/graduation-portrait')->assertDontSee($link, false);
    }

    public function test_the_customer_menu_and_dashboard_offer_the_wizard(): void
    {
        $this->actingAs($this->customer)->get(route('customer.dashboard'))
            ->assertSee(route('customer.bookings.create'), false)
            ->assertSee('Book a photoshoot');
    }

    public function test_a_package_from_the_public_pages_is_chosen_and_the_wizard_opens_at_the_details(): void
    {
        $html = $this->actingAs($this->customer)->get(route('customer.bookings.create', ['package' => 'graduation-portrait']))->assertOk()->getContent();

        $this->assertSame(2, $this->openStep($html));
        $this->assertMatchesRegularExpression('/name="package" value="'.$this->package->id.'"[^>]*checked/', $html);
        $this->assertStringContainsString('This package is for 1 to 3 people.', $html);
    }

    public function test_an_unknown_or_unpublished_package_address_falls_back_to_step_one(): void
    {
        $hidden = PhotographyPackage::factory()->create(['slug' => 'hidden']);

        foreach (['no-such-package', 'hidden', '<script>'] as $slug) {
            $html = $this->actingAs($this->customer)->get(route('customer.bookings.create', ['package' => $slug]))->assertOk()->getContent();

            $this->assertSame(1, $this->openStep($html));
            $this->assertDoesNotMatchRegularExpression('/name="package" value="\d+"[^>]*checked/', $html);
            $this->assertStringNotContainsString('value="'.$hidden->id.'" class="peer', $html);
        }
    }

    public function test_only_published_packages_and_their_published_themes_are_offered(): void
    {
        PhotographyPackage::factory()->create(['name' => 'Secret Draft Package']);
        $hiddenTheme = Theme::factory()->create(['name' => 'Unpublished Backdrop']);
        $this->package->themes()->attach($hiddenTheme);

        $this->actingAs($this->customer)->get(route('customer.bookings.create'))
            ->assertSee('Graduation Portrait')
            ->assertSee('₱1,850.00')
            ->assertSee('Classic Crimson')
            ->assertDontSee('Secret Draft Package')
            ->assertDontSee('Unpublished Backdrop');
    }

    public function test_the_page_states_the_booking_window_from_the_settings(): void
    {
        $this->settings(noticeHours: 48, advanceDays: 14);

        $this->actingAs($this->customer)->get(route('customer.bookings.create'))
            ->assertSee('Book at least 48 hours ahead and up to 14 days ahead.')
            ->assertSee('Times are in Philippine time.');
    }

    public function test_the_form_works_without_scripts(): void
    {
        $html = $this->actingAs($this->customer)->get(route('customer.bookings.create', ['package' => 'graduation-portrait']))->getContent();

        // One multipart form; every step is on the page
        $this->assertSame(1, substr_count($html, 'enctype="multipart/form-data"'));
        foreach (range(1, 6) as $n) {
            $this->assertStringContainsString('id="step-'.$n.'"', $html);
        }

        // The free times as plain radio buttons, shown only without scripts; the scripted field is off until Alpine runs
        $this->assertStringContainsString('data-no-js-only x-bind:disabled="true"', $html);
        $this->assertStringContainsString('name="starts_at" value="'.self::SLOT.'"', $html);
        $this->assertStringContainsString('Thu, Nov 5, 2026', $html);
        $this->assertMatchesRegularExpression('/<input type="hidden" name="starts_at" x-bind:value="selected" disabled/', $html);

        // Without scripts a package is chosen through a plain link
        $this->assertStringContainsString(e(route('customer.bookings.create', ['package' => 'graduation-portrait'])), $html);
        $this->assertStringNotContainsString(' onclick=', $html);
    }

    public function test_without_scripts_and_without_a_package_the_page_says_how_to_continue(): void
    {
        $this->actingAs($this->customer)->get(route('customer.bookings.create'))
            ->assertSee('Choose a package first: pick “Use this package” in step 1 to see its free times here.', false);
    }

    // Sending

    public function test_a_request_is_saved_with_its_images_reference_and_first_history_row(): void
    {
        $response = $this->send(['images' => [$this->jpeg('pose 1.jpg'), $this->jpeg('pose 2.jpg')], 'custom_theme_text' => 'Lots of flowers.']);

        $booking = PhotographyBooking::sole();
        $response->assertRedirect(route('customer.bookings.sent', $booking));

        $this->assertSame('PB-000001', $booking->reference_number);
        $this->assertSame(BookingStatus::Requested, $booking->status);
        $this->assertSame($this->customer->id, $booking->customer_id);
        $this->assertSame('2026-11-05 02:00:00', $booking->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $booking->group_size);
        $this->assertSame('0917 123 4567', $booking->contact_number);
        $this->assertSame('Bringing a toga.', $booking->notes);
        $this->assertSame($this->theme->id, $booking->theme_id);
        $this->assertSame('Lots of flowers.', $booking->custom_theme_text);
        $this->assertTrue($booking->wants_prints);
        $this->assertSame('1850.00', $booking->estimated_price);
        $this->assertNull($booking->confirmed_price);

        $this->assertCount(2, $booking->images);
        foreach ($booking->images as $image) {
            Storage::disk('local')->assertExists($image->path);
            $this->assertStringStartsWith("booking-images/{$booking->id}/", $image->path);
        }
        $this->assertSame([], Storage::disk('public')->allFiles());

        $history = BookingStatusHistory::sole();
        $this->assertNull($history->from_status);
        $this->assertSame(BookingStatus::Requested, $history->to_status);
    }

    public function test_digital_only_and_an_own_idea_without_a_listed_backdrop(): void
    {
        $this->send(['prints' => 'digital', 'theme' => '', 'custom_theme_text' => '  Pastel garden  '])->assertRedirect();

        $booking = PhotographyBooking::sole();
        $this->assertFalse($booking->wants_prints);
        $this->assertNull($booking->theme_id);
        $this->assertSame('Pastel garden', $booking->custom_theme_text);
    }

    public function test_the_request_page_shows_the_request(): void
    {
        $this->send();
        $booking = PhotographyBooking::sole();

        $this->actingAs($this->customer)->get(route('customer.bookings.sent', $booking))->assertOk()
            ->assertSee('PB-000001')
            ->assertSee('Copy reference')
            ->assertSee('Graduation Portrait')
            ->assertSee('Thu, Nov 5, 2026')
            ->assertSee('10:00 AM')
            ->assertSee('11:00 AM')
            ->assertSee('Requested')
            ->assertSee('₱1,850.00')
            ->assertSee('What happens next')
            ->assertSee(route('customer.bookings.create'), false)
            ->assertSee(route('customer.dashboard'), false);
    }

    public function test_another_customer_cannot_open_the_request_page(): void
    {
        $booking = PhotographyBooking::factory()->forCustomer($this->customer)->create();

        $this->actingAs(User::factory()->create())->get(route('customer.bookings.sent', $booking))->assertForbidden();
    }

    public function test_hostile_text_is_escaped(): void
    {
        $this->send(['custom_theme_text' => '<script>alert("idea")</script>', 'images' => [$this->jpeg('<img src=x onerror=alert(1)>.jpg')]]);
        $booking = PhotographyBooking::sole();

        $html = $this->actingAs($this->customer)->get(route('customer.bookings.sent', $booking))->getContent();

        $this->assertStringNotContainsString('<script>alert("idea")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;idea&quot;)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidChoices(): array
    {
        return [
            'no package' => [['package' => ''], 'package'],
            'group size zero' => [['group_size' => 0], 'group_size'],
            'group size above the package' => [['group_size' => 4], 'group_size'],
            'group size not a number' => [['group_size' => 'two'], 'group_size'],
            'no contact number' => [['contact_number' => ''], 'contact_number'],
            'contact number too short' => [['contact_number' => '12345'], 'contact_number'],
            'contact number with letters' => [['contact_number' => '0917-ABC-4567'], 'contact_number'],
            'notes too long' => [['notes' => str_repeat('a', 2001)], 'notes'],
            'own idea too long' => [['custom_theme_text' => str_repeat('a', 501)], 'custom_theme_text'],
            'neither a backdrop nor an own idea' => [['theme' => '', 'custom_theme_text' => '  '], 'theme'],
            'no time' => [['starts_at' => ''], 'starts_at'],
            'a time in another format' => [['starts_at' => '2026-11-05 10:00'], 'starts_at'],
            'prints not a choice' => [['prints' => 'yes please'], 'prints'],
        ];
    }

    #[DataProvider('invalidChoices')]
    public function test_every_rule_is_checked_on_the_server(array $overrides, string $field): void
    {
        $this->send($overrides)->assertRedirect(route('customer.bookings.create'))->assertSessionHasErrors($field);

        $this->assertNothingSaved();
    }

    public function test_the_messages_are_the_services_own(): void
    {
        $this->send(['group_size' => 4, 'contact_number' => '12', 'theme' => '', 'custom_theme_text' => ''])
            ->assertSessionHasErrors([
                'group_size' => 'This package is for 1 to 3 people.',
                'contact_number' => BookingService::CONTACT_NUMBER_MESSAGE,
                'theme' => BookingService::THEME_OR_IDEA_MESSAGE,
            ]);
    }

    public function test_a_theme_that_is_not_linked_unpublished_or_removed_is_refused(): void
    {
        $notLinked = Theme::factory()->published()->create();
        $hidden = Theme::factory()->create();
        $this->package->themes()->attach($hidden);
        $removed = Theme::factory()->published()->create();
        $this->package->themes()->attach($removed);
        $removed->delete();

        foreach ([$notLinked->id, $hidden->id, $removed->id, 999999] as $theme) {
            $this->send(['theme' => $theme])->assertSessionHasErrors(['theme' => BookingService::THEME_MESSAGE]);
        }

        $this->assertNothingSaved();
    }

    public function test_an_unpublished_or_removed_package_is_refused(): void
    {
        $draft = PhotographyPackage::factory()->create(['duration_minutes' => 60, 'max_people' => 3]);
        $removed = PhotographyPackage::factory()->published()->create(['duration_minutes' => 60, 'max_people' => 3]);
        $removed->delete();

        foreach ([$draft->id, $removed->id] as $package) {
            $this->send(['package' => $package, 'theme' => '', 'custom_theme_text' => 'Idea'])->assertSessionHasErrors(['package' => BookingService::PACKAGE_MESSAGE]);
        }

        $this->assertNothingSaved();
    }

    /** @return array<string, array{string}> */
    public static function tamperedSlots(): array
    {
        return [
            'not on the slot grid' => ['2026-11-05T02:10:00Z'],
            'in the past' => ['2026-11-01T02:00:00Z'],
            'inside the notice period' => ['2026-11-02T06:00:00Z'],
            'beyond how far ahead one may book' => ['2026-12-04T02:00:00Z'],
            'on a closed day (Sunday)' => ['2026-11-08T02:00:00Z'],
            'running past closing time' => ['2026-11-05T08:30:00Z'],
            'before opening time' => ['2026-11-05T00:00:00Z'],
        ];
    }

    #[DataProvider('tamperedSlots')]
    public function test_a_tampered_time_is_refused(string $startsAt): void
    {
        $this->send(['starts_at' => $startsAt])->assertSessionHasErrors(['starts_at' => BookingService::SLOT_TAKEN_MESSAGE]);

        $this->assertNothingSaved();
    }

    public function test_a_time_on_a_blocked_date_is_refused(): void
    {
        BlockedDate::factory()->create(['date' => '2026-11-05']);

        $this->send()->assertSessionHasErrors('starts_at');
        $this->assertNothingSaved();
    }

    public function test_a_time_taken_meanwhile_returns_to_the_date_step_and_keeps_everything_else(): void
    {
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at($this->manila('2026-11-05 10:00'))->create();

        // (Asserting the session errors here would use them up before the next page reads them)
        $this->send(['notes' => 'Keep me', 'contact_number' => '0918 765 4321'])->assertRedirect(route('customer.bookings.create'));

        $html = $this->actingAs($this->customer)->get(route('customer.bookings.create'))->getContent();

        $this->assertSame(3, $this->openStep($html));
        $this->assertStringContainsString('Keep me', $html);
        $this->assertStringContainsString('value="0918 765 4321"', $html);
        $this->assertStringContainsString(BookingService::SLOT_TAKEN_MESSAGE, $html);
        $this->assertStringContainsString('Images are not kept when something needs fixing.', $html);
        $this->assertSame(1, PhotographyBooking::count());
    }

    public function test_a_failed_send_opens_the_first_step_with_a_problem(): void
    {
        $this->send(['contact_number' => '', 'prints' => 'nope']);
        $this->assertSame(2, $this->openStep($this->actingAs($this->customer)->get(route('customer.bookings.create'))->getContent()));

        $this->send(['theme' => '', 'custom_theme_text' => '']);
        $this->assertSame(4, $this->openStep($this->actingAs($this->customer)->get(route('customer.bookings.create'))->getContent()));
    }

    // Images

    public function test_image_rules_are_checked_and_nothing_is_kept_when_one_fails(): void
    {
        $cases = [
            'wrong content' => [[$this->file('pose.jpg', '<?php echo 1;')], 'images.0'],
            'extension does not match the content' => [[$this->file('pose.png', file_get_contents($this->jpeg()->getPathname()))], 'images.0'],
            'type not allowed' => [[$this->file('pose.gif', 'GIF89a')], 'images.0'],
            'too large' => [[$this->jpeg('huge.jpg', 5 * 1024 * 1024 + 1)], 'images.0'],
            'too many' => [array_map(fn ($i) => $this->jpeg("p{$i}.jpg"), range(1, 6)), 'images'],
            'too much together' => [array_map(fn ($i) => $this->jpeg("big{$i}.jpg", 4 * 1024 * 1024), range(1, 4)), 'images'],
        ];

        foreach ($cases as $name => [$files, $field]) {
            $this->send(['images' => $files])->assertSessionHasErrors($field);
            $this->assertNothingSaved();
        }
    }

    // The open request limit

    public function test_a_customer_at_the_request_limit_sees_it_and_cannot_send(): void
    {
        PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Requested)->count(2)->create();
        PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Proposed)->create();

        $this->actingAs($this->customer)->get(route('customer.bookings.create'))
            ->assertSee(BookingService::requestLimitMessage())
            ->assertSee('type="submit" disabled', false);

        $this->send()->assertSessionHasErrors(['booking' => BookingService::requestLimitMessage()]);
        $this->assertSame(3, PhotographyBooking::count());
    }

    public function test_below_the_limit_the_send_button_is_open(): void
    {
        PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Requested)->count(2)->create();
        PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Cancelled)->create();

        $this->actingAs($this->customer)->get(route('customer.bookings.create'))
            ->assertDontSee(BookingService::requestLimitMessage())
            ->assertDontSee('type="submit" disabled', false);
    }

    // The slot list

    public function test_the_slot_list_gives_free_times_in_local_and_utc_form(): void
    {
        $response = $this->slots(['from' => '2026-11-05', 'to' => '2026-11-05'])->assertOk();

        $response->assertJsonPath('bookable_from', '2026-11-03')
            ->assertJsonPath('bookable_until', '2026-12-02')
            ->assertJsonPath('days.2026-11-05.0', [
                'starts_at' => '2026-11-05T01:00:00Z',
                'date' => '2026-11-05',
                'time' => '9:00 AM',
                'time_text' => '9:00 AM – 10:00 AM',
                'date_text' => 'Thu, Nov 5, 2026',
            ]);
        // 9:00 to 16:00 every 30 minutes for a 60-minute session
        $this->assertCount(15, $response->json('days.2026-11-05'));
    }

    public function test_a_request_does_not_take_a_slot_but_a_confirmed_booking_does(): void
    {
        PhotographyBooking::factory()->status(BookingStatus::Requested)->at($this->manila('2026-11-05 10:00'))->create();
        $times = collect($this->slots(['from' => '2026-11-05', 'to' => '2026-11-05'])->json('days.2026-11-05'))->pluck('starts_at');
        $this->assertContains(self::SLOT, $times);

        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at($this->manila('2026-11-05 10:00'))->create();
        $times = collect($this->slots(['from' => '2026-11-05', 'to' => '2026-11-05'])->json('days.2026-11-05'))->pluck('starts_at');
        $this->assertNotContains(self::SLOT, $times);
        // 9:30 overlaps too; 9:00 ends at 10:00 and 11:00 starts when it ends
        $this->assertNotContains('2026-11-05T01:30:00Z', $times);
        $this->assertContains('2026-11-05T01:00:00Z', $times);
        $this->assertContains('2026-11-05T03:00:00Z', $times);
    }

    public function test_closed_blocked_past_and_far_days_have_no_slots(): void
    {
        BlockedDate::factory()->create(['date' => '2026-11-06']);

        $days = $this->slots(['from' => '2026-11-01', 'to' => '2026-12-31'])->assertOk()->json('days');

        $this->assertArrayNotHasKey('2026-11-02', $days, 'Inside the notice period');
        $this->assertArrayNotHasKey('2026-11-06', $days, 'Blocked');
        $this->assertArrayNotHasKey('2026-11-08', $days, 'Sunday');
        $this->assertArrayNotHasKey('2026-12-03', $days, 'Beyond 30 days');
        $this->assertArrayHasKey('2026-11-05', $days);
        $this->assertArrayHasKey('2026-12-01', $days);
    }

    public function test_a_fully_booked_day_has_no_slots_and_says_nothing_about_who_booked(): void
    {
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at($this->manila('2026-11-05 09:00'), 480)->create(['notes' => 'Secret note']);

        $response = $this->slots(['from' => '2026-11-05', 'to' => '2026-11-05'])->assertOk();

        $this->assertSame([], (array) $response->json('days'));
        $this->assertStringNotContainsString('Secret note', $response->getContent());
        $this->assertStringNotContainsString('PB-', $response->getContent());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badRanges(): array
    {
        return [
            'no dates' => [[], 'from'],
            'backwards' => [['from' => '2026-11-10', 'to' => '2026-11-05'], 'to'],
            'too long' => [['from' => '2026-11-01', 'to' => '2027-01-02'], 'to'],
            'not a date' => [['from' => 'next week', 'to' => '2026-11-05'], 'from'],
            'unknown package' => [['package' => 999999, 'from' => '2026-11-05', 'to' => '2026-11-05'], 'package'],
        ];
    }

    #[DataProvider('badRanges')]
    public function test_the_slot_list_validates_its_range_and_package(array $query, string $field): void
    {
        $this->slots($query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_the_slot_list_refuses_an_unpublished_package(): void
    {
        $draft = PhotographyPackage::factory()->create();

        $this->actingAs($this->customer)->getJson(route('customer.bookings.slots', ['package' => $draft->id, 'from' => '2026-11-05', 'to' => '2026-11-05']))
            ->assertUnprocessable()->assertJsonValidationErrors('package');
    }

    // Throttles

    public function test_the_slot_list_and_sending_have_separate_limits(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->slots(['from' => '2026-11-08', 'to' => '2026-11-08'])->assertOk();
        }
        $this->slots(['from' => '2026-11-08', 'to' => '2026-11-08'])->assertTooManyRequests();

        // Browsing the calendar never uses up sending
        $this->send()->assertRedirect();
        $this->assertSame(1, PhotographyBooking::count());
    }

    public function test_sending_is_limited_without_touching_the_slot_list(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->send(['contact_number' => '']);
        }
        $this->send()->assertTooManyRequests();

        $this->slots(['from' => '2026-11-05', 'to' => '2026-11-05'])->assertOk();
        $this->actingAs($this->customer)->get(route('customer.bookings.create'))->assertOk();
    }

    // Status badge

    public function test_the_status_badge_uses_the_booking_words_and_colours(): void
    {
        $expected = [
            'requested' => ['Requested', 'bg-status-amber-bg'],
            'proposed' => ['New time proposed', 'bg-status-blue-bg'],
            'confirmed' => ['Confirmed', 'bg-status-green-bg'],
            'rejected' => ['Rejected', 'bg-status-red-bg'],
            'cancelled' => ['Cancelled', 'bg-status-gray-bg'],
            'completed' => ['Completed', 'bg-status-gray-bg'],
        ];

        foreach (BookingStatus::cases() as $status) {
            $html = (string) $this->blade('<x-ui.status-badge :status="$status" live />', ['status' => $status]);

            $this->assertStringContainsString($expected[$status->value][0], $html);
            $this->assertStringContainsString($expected[$status->value][1], $html);
        }

        $this->assertStringContainsString('animate-ping', (string) $this->blade('<x-ui.status-badge :status="$s" live />', ['s' => BookingStatus::Requested]));
        $this->assertStringContainsString('animate-pulse', (string) $this->blade('<x-ui.status-badge :status="$s" live />', ['s' => BookingStatus::Proposed]));
    }
}
