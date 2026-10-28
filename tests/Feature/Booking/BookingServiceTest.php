<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\BookingReferenceImage;
use App\Models\BookingStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Models\User;
use App\Services\BookingImageService;
use App\Services\BookingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $customer;

    private PhotographyPackage $package;

    private Theme $theme;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpSchedule();

        $this->customer = User::factory()->create();
        $this->package = PhotographyPackage::factory()->published()->create([
            'name' => 'Graduation Portrait',
            'price' => '1850.00',
            'price_requires_confirmation' => false,
            'duration_minutes' => 60,
            'max_people' => 3,
            'inclusions' => ['10 edited photos', '1 framed print'],
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
        $image = imagecreatetruecolor(40, 30);
        $path = tempnam(sys_get_temp_dir(), 'img');
        imagejpeg($image, $path);
        if ($padTo > 0) {
            // Bytes after the end-of-image marker are ignored by readers but count for the size
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

    private function place(array $overrides = []): PhotographyBooking
    {
        $args = array_replace([
            'customer' => $this->customer,
            'package' => $this->package,
            'theme' => $this->theme,
            'customThemeText' => null,
            'startsAt' => $this->manila('2026-11-05 10:00'),
            'groupSize' => 2,
            'contactNumber' => '  +63 (917) 123-4567  ',
            'notes' => 'Bringing a toga.',
            'wantsPrints' => true,
            'uploads' => [],
        ], $overrides);

        return app(BookingService::class)->place(...$args);
    }

    private function assertRefused(array $overrides, string $field): void
    {
        try {
            $this->place($overrides);
            $this->fail("The booking should have been refused on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }

        $this->assertSame(0, PhotographyBooking::withTrashed()->count());
        $this->assertSame(0, BookingReferenceImage::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_request_is_saved_with_its_snapshot_estimate_images_and_first_history_row(): void
    {
        $booking = $this->place(['uploads' => [$this->jpeg('pose 1.jpg'), $this->jpeg('pose 2.jpg')]])->fresh();

        $this->assertSame('PB-000001', $booking->reference_number);
        $this->assertSame(BookingStatus::Requested, $booking->status);
        $this->assertSame($this->customer->id, $booking->customer_id);
        $this->assertSame('2026-11-05 02:00:00', $booking->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 03:00:00', $booking->ends_at->format('Y-m-d H:i:s'));
        $this->assertTrue($booking->requested_starts_at->eq($booking->starts_at));
        $this->assertSame(2, $booking->group_size);
        $this->assertSame('+63 (917) 123-4567', $booking->contact_number);
        $this->assertTrue($booking->wants_prints);
        $this->assertSame('Bringing a toga.', $booking->notes);
        $this->assertSame('1850.00', $booking->estimated_price);
        $this->assertNull($booking->confirmed_price);
        $this->assertSame($this->theme->id, $booking->theme_id);

        $this->assertSame('Graduation Portrait', $booking->snapshot['package']['name']);
        $this->assertSame(60, $booking->snapshot['package']['duration_minutes']);
        $this->assertSame('fixed', $booking->snapshot['package']['price_case']);
        $this->assertSame('₱1,850.00', $booking->snapshot['package']['price_text']);
        $this->assertSame(['10 edited photos', '1 framed print'], $booking->snapshot['package']['inclusions']);
        $this->assertSame('Classic Crimson', $booking->snapshot['theme']['name']);

        $this->assertSame(['pose 1.jpg', 'pose 2.jpg'], $booking->images()->orderBy('id')->pluck('original_name')->all());
        foreach ($booking->images as $image) {
            Storage::disk('local')->assertExists($image->path);
            $this->assertStringStartsWith("booking-images/{$booking->id}/", $image->path);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.jpg$/', $image->stored_name);
            $this->assertSame('image/jpeg', $image->mime);
        }

        $history = BookingStatusHistory::sole();
        $this->assertNull($history->from_status);
        $this->assertSame(BookingStatus::Requested, $history->to_status);
        $this->assertSame('Booking requested', $history->title());
    }

    public function test_the_three_price_cases_are_snapshotted(): void
    {
        $from = PhotographyPackage::factory()->published()->create(['price' => '1200.00', 'price_requires_confirmation' => true, 'duration_minutes' => 60, 'max_people' => 2]);
        $onRequest = PhotographyPackage::factory()->published()->create(['price' => null, 'price_requires_confirmation' => false, 'duration_minutes' => 60, 'max_people' => 2]);

        $a = $this->place(['package' => $from, 'theme' => null]);
        $b = $this->place(['package' => $onRequest, 'theme' => null, 'startsAt' => $this->manila('2026-11-05 13:00')]);

        $this->assertSame('from', $a->snapshot['package']['price_case']);
        $this->assertSame('1200.00', $a->estimated_price);
        $this->assertStringStartsWith('From ₱1,200.00', $a->snapshot['package']['price_text']);
        $this->assertSame('on_request', $b->snapshot['package']['price_case']);
        $this->assertNull($b->estimated_price);
        $this->assertSame('Price on request', $b->estimateText());
    }

    public function test_a_custom_theme_instead_of_a_listed_one(): void
    {
        $this->assertRefused(['theme' => null, 'customThemeText' => str_repeat('a', 501)], 'custom_theme_text');

        $booking = $this->place(['theme' => null, 'customThemeText' => '  Pastel garden, lots of flowers.  ']);

        $this->assertNull($booking->theme_id);
        $this->assertSame('Pastel garden, lots of flowers.', $booking->custom_theme_text);
        $this->assertSame('Pastel garden, lots of flowers.', $booking->snapshot['custom_theme_text']);
        $this->assertSame(500, mb_strlen($this->place(['theme' => null, 'customThemeText' => str_repeat('b', 500), 'startsAt' => $this->manila('2026-11-05 14:00')])->custom_theme_text));
    }

    public function test_a_time_taken_in_the_meantime_is_refused(): void
    {
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at($this->manila('2026-11-05 10:00'))->create();

        try {
            $this->place();
            $this->fail('A taken time must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('starts_at', $e->errors());
        }

        $this->assertSame(1, PhotographyBooking::count());
    }

    public function test_a_time_requested_by_someone_else_is_still_free(): void
    {
        PhotographyBooking::factory()->status(BookingStatus::Requested)->at($this->manila('2026-11-05 10:00'))->create();

        $this->assertSame(BookingStatus::Requested, $this->place()->status);
    }

    public function test_times_outside_the_rules_are_refused(): void
    {
        foreach (['2026-11-08 10:00', '2026-11-05 16:30', '2026-11-02 10:00', '2026-11-05 10:10'] as $time) {
            $this->assertRefused(['startsAt' => $this->manila($time)], 'starts_at');
        }
    }

    public function test_an_unpublished_or_removed_package_is_refused(): void
    {
        $this->assertRefused(['package' => PhotographyPackage::factory()->create(['duration_minutes' => 60, 'max_people' => 3]), 'theme' => null], 'package');

        $removed = PhotographyPackage::factory()->published()->create(['duration_minutes' => 60, 'max_people' => 3]);
        $removed->delete();
        $this->assertRefused(['package' => $removed, 'theme' => null], 'package');
    }

    public function test_a_theme_that_is_unpublished_removed_or_not_linked_is_refused(): void
    {
        $hidden = Theme::factory()->create();
        $this->package->themes()->attach($hidden);
        $this->assertRefused(['theme' => $hidden], 'theme');

        $notLinked = Theme::factory()->published()->create();
        $this->assertRefused(['theme' => $notLinked], 'theme');

        $this->theme->delete();
        $this->assertRefused(['theme' => $this->theme], 'theme');
    }

    public function test_the_group_size_must_fit_the_package(): void
    {
        $this->assertRefused(['groupSize' => 0], 'group_size');
        $this->assertRefused(['groupSize' => 4], 'group_size');

        $this->assertSame(3, $this->place(['groupSize' => 3])->group_size);
    }

    public function test_the_contact_number_must_be_7_to_20_digits(): void
    {
        foreach (['', '   ', '123456', '0917-ABC-4567', '0917 123 4567 ext 2', '0917.123.4567', str_repeat('1', 21), '+63 '.str_repeat('(9) ', 7).'1'] as $bad) {
            $this->assertRefused(['contactNumber' => $bad], 'contact_number');
        }

        $this->assertSame('1234567', $this->place(['contactNumber' => '1234567'])->contact_number);
        $this->assertSame(str_repeat('9', 20), $this->place(['contactNumber' => str_repeat('9', 20), 'startsAt' => $this->manila('2026-11-05 13:00')])->contact_number);
    }

    public function test_a_customer_with_3_open_requests_cannot_send_another(): void
    {
        PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Requested)->count(2)->create();
        PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Proposed)->create();

        try {
            $this->place();
            $this->fail('A fourth open request must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['You already have 3 requests waiting for the studio. Please wait for a reply or cancel one.'], $e->errors()['booking']);
        }

        $this->assertSame(3, PhotographyBooking::count());
        $this->assertSame(0, BookingStatusHistory::count());
    }

    public function test_one_below_the_limit_is_allowed_and_closed_bookings_do_not_count(): void
    {
        PhotographyBooking::factory()->forCustomer($this->customer)->status(BookingStatus::Requested)->count(2)->create();
        foreach ([BookingStatus::Cancelled, BookingStatus::Rejected, BookingStatus::Confirmed, BookingStatus::Completed] as $i => $status) {
            PhotographyBooking::factory()->forCustomer($this->customer)->status($status)->at($this->manila('2026-11-1'.$i.' 10:00'))->create();
        }

        $this->assertSame(BookingStatus::Requested, $this->place()->status);

        // That was the third; the next one is refused
        try {
            $this->place(['startsAt' => $this->manila('2026-11-05 13:00')]);
            $this->fail('A fourth open request must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('booking', $e->errors());
        }
    }

    public function test_another_customers_requests_do_not_count(): void
    {
        $other = User::factory()->create();
        PhotographyBooking::factory()->forCustomer($other)->status(BookingStatus::Requested)->count(3)->create();

        $this->assertSame(BookingStatus::Requested, $this->place()->status);
        $this->assertSame(1, PhotographyBooking::query()->where('customer_id', $this->customer->id)->count());
    }

    public function test_the_owner_cannot_request_a_booking(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->place(['customer' => User::factory()->owner()->create()]);
    }

    public function test_images_with_the_wrong_content_or_type_are_refused(): void
    {
        $this->assertRefused(['uploads' => [$this->file('pose.jpg', '<?php echo 1;')]], 'images');
        $this->assertRefused(['uploads' => [$this->file('pose.png', file_get_contents($this->jpeg()->getPathname()))]], 'images');
        $this->assertRefused(['uploads' => [$this->file('pose.gif', 'GIF89a')]], 'images');
        $this->assertRefused(['uploads' => [$this->file('pose.jpg', '')]], 'images');
        $this->assertRefused(['uploads' => [$this->file('shell.php.jpg', '<?php echo 1;')]], 'images');
    }

    public function test_too_many_images_or_too_big_together_are_refused(): void
    {
        $this->assertRefused(['uploads' => array_map(fn ($i) => $this->jpeg("p{$i}.jpg"), range(1, 6))], 'images');

        // Four images of 4 MB each pass one by one (5 MB each) but are 16 MB together (15 MB)
        $big = array_map(fn ($i) => $this->jpeg("big{$i}.jpg", 4 * 1024 * 1024), range(1, 4));
        try {
            $this->place(['uploads' => $big]);
            $this->fail('Too much in total must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('The most one booking can hold is 15 MB.', $e->errors()['images'][0]);
        }

        $this->assertRefused(['uploads' => [$this->jpeg('huge.jpg', 5 * 1024 * 1024 + 1)]], 'images');
    }

    public function test_hostile_file_names_are_cleaned_for_display_and_never_used_on_disk(): void
    {
        $booking = $this->place(['uploads' => [$this->jpeg("../../etc/pa\x00ss\nwd.jpg")]]);
        $image = $booking->images->first();

        $this->assertStringNotContainsString('/', $image->original_name);
        $this->assertStringNotContainsString("\x00", $image->original_name);
        $this->assertStringNotContainsString('..', $image->path);
        $this->assertStringStartsWith("booking-images/{$booking->id}/", $image->path);
    }

    public function test_a_failure_after_an_image_is_written_leaves_nothing_behind(): void
    {
        $this->app->instance(BookingImageService::class, new class extends BookingImageService
        {
            public int $calls = 0;

            public function store(PhotographyBooking $booking, UploadedFile $file, User $uploader): BookingReferenceImage
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('The disk is full.');
                }

                return parent::store($booking, $file, $uploader);
            }
        });

        try {
            $this->place(['uploads' => [$this->jpeg('a.jpg'), $this->jpeg('b.jpg')]]);
            $this->fail('The booking should have failed.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, PhotographyBooking::withTrashed()->count());
        $this->assertSame(0, BookingReferenceImage::count());
        $this->assertSame(0, BookingStatusHistory::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allDirectories(), fn ($dir) => str_contains($dir, '/'))));

        // The reference number was given back with the rest
        $this->app->forgetInstance(BookingImageService::class);
        $this->app->forgetInstance(BookingService::class);
        $this->assertSame('PB-000001', $this->place()->reference_number);
    }
}
