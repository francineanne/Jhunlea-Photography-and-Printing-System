<?php

namespace Tests\Feature\Settings;

use App\Enums\BookingStatus;
use App\Models\BookingReferenceImage;
use App\Models\OrderFile;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\BookingImageService;
use App\Services\OrderFileService;
use App\Support\ServerUploadLimits;
use App\Support\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 6B: ShopSettings is the one class that reads every upload limit. It returns the config default until the owner
 * saves one, never more than the server accepts, and everything that checks or shows a limit follows it.
 */
class UploadLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private const MB = 1024 * 1024;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->server('10M', '40M', '20');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** Pretends this PHP server has these php.ini values. */
    private function server(string $file, string $post, string $files): void
    {
        $this->app->instance(ServerUploadLimits::class, new ServerUploadLimits(['upload_max_filesize' => $file, 'post_max_size' => $post, 'max_file_uploads' => $files]));
        $this->app->forgetScopedInstances();
    }

    /** @param  array<string, int|null>  $limits */
    private function save(array $limits): void
    {
        $row = ShopSetting::query()->find(ShopSetting::ROW_ID) ?? (new ShopSetting)->forceFill(['id' => ShopSetting::ROW_ID]);
        $row->forceFill($limits)->save();
    }

    private function settings(): ShopSettings
    {
        return app(ShopSettings::class);
    }

    private function pdf(int $bytes, string $name = 'doc.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, self::PDF.str_repeat(' ', max(0, $bytes - strlen(self::PDF))));
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function jpeg(int $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        imagejpeg(imagecreatetruecolor(40, 30), $path);
        file_put_contents($path, str_repeat("\0", max(0, $bytes - filesize($path))), FILE_APPEND);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'pose.jpg', null, null, true);
    }

    private function refused(callable $check): string
    {
        try {
            $check();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        $this->fail('The upload was accepted.');
    }

    public function test_the_config_defaults_are_used_until_the_owner_saves(): void
    {
        $s = $this->settings();

        $this->assertSame([10, 30, 10, 5, 5, 15], [$s->printingMaxFileMb(), $s->printingMaxTotalMb(), $s->printingMaxFiles(), $s->bookingMaxImageMb(), $s->bookingMaxImages(), $s->bookingMaxTotalMb()]);
        $this->assertNull($s->storedFor('printing_max_file_mb'));
        $this->assertNull($s->shopName());

        $this->save(['printing_max_file_mb' => 4, 'printing_max_total_mb' => 12, 'printing_max_files' => 3, 'booking_max_image_mb' => 2, 'booking_max_images' => 2, 'booking_max_total_mb' => 4]);
        $s = $this->settings();

        $this->assertSame([4, 12, 3, 2, 2, 4], [$s->printingMaxFileMb(), $s->printingMaxTotalMb(), $s->printingMaxFiles(), $s->bookingMaxImageMb(), $s->bookingMaxImages(), $s->bookingMaxTotalMb()]);
        $this->assertSame(4, $s->storedFor('printing_max_file_mb'));
    }

    public function test_a_value_above_the_server_maximum_is_capped_when_read(): void
    {
        $this->save(['printing_max_file_mb' => 10, 'printing_max_total_mb' => 38, 'printing_max_files' => 20, 'booking_max_images' => 20]);

        // php.ini lowered after the owner saved
        $this->server('4M', '12M', '6');
        $s = $this->settings();

        $this->assertSame(4, $s->printingMaxFileMb());
        $this->assertSame(10, $s->printingMaxTotalMb());
        $this->assertSame(6, $s->printingMaxFiles());
        $this->assertSame(6, $s->bookingMaxImages());
        $this->assertTrue($s->capped('printing_max_file_mb'));
        $this->assertTrue($s->capped('printing_max_total_mb'));
        // Nothing saved for this one, but its starting default (5 MB) is above the server's 4 MB too
        $this->assertTrue($s->capped('booking_max_image_mb'));
        $this->assertSame(4, $s->bookingMaxImageMb());

        $this->server('10M', '40M', '20');
        $this->assertFalse(app(ShopSettings::class)->capped('booking_max_image_mb'));
        // The starting default (10 MB) is capped too
        $this->assertSame(4, $s->defaultFor('printing_max_file_mb'));
    }

    public function test_no_code_outside_shop_settings_reads_the_upload_limits_from_config(): void
    {
        $keys = ['printing.max_file_size_mb', 'printing.max_total_size_mb', 'printing.max_files_per_order', 'booking.max_image_size_mb', 'booking.max_images_per_booking', 'booking.max_total_size_mb'];
        $offenders = [];

        foreach ([app_path(), resource_path('views'), resource_path('js'), base_path('routes')] as $folder) {
            foreach (File::allFiles($folder) as $file) {
                if ($file->getRealPath() === realpath(app_path('Support/ShopSettings.php'))) {
                    continue;
                }
                foreach ($keys as $key) {
                    if (str_contains($file->getContents(), $key)) {
                        $offenders[] = $file->getRelativePathname().' reads '.$key;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_order_files_follow_the_limits_at_the_boundary(): void
    {
        $service = PrintingService::factory()->published()->create(['allowed_file_types' => ['pdf'], 'max_file_size_mb' => 10]);
        $files = app(OrderFileService::class);
        $this->save(['printing_max_file_mb' => 1, 'printing_max_total_mb' => 2]);

        $files->validate($this->pdf(self::MB), $service);
        $this->assertSame('The file is too large. The most this service accepts is 1 MB.', $this->refused(fn () => $files->validate($this->pdf(2 * self::MB), $service)));

        $files->validateTotal([$this->pdf(self::MB), $this->pdf(self::MB)]);
        $this->assertStringContainsString('The most one order can hold is 2 MB.', $this->refused(fn () => $files->validateTotal([$this->pdf(2 * self::MB), $this->pdf(self::MB)])));

        // Raised again: the 2 MB file passes
        $this->save(['printing_max_file_mb' => 2, 'printing_max_total_mb' => 4]);
        app(OrderFileService::class)->validate($this->pdf(2 * self::MB), $service);
    }

    public function test_a_service_limit_above_the_global_one_is_capped(): void
    {
        $service = PrintingService::factory()->published()->create(['allowed_file_types' => ['pdf'], 'max_file_size_mb' => 8]);
        $this->save(['printing_max_file_mb' => 3]);

        $this->assertSame(3 * 1024, app(OrderFileService::class)->maxKilobytes($service));
        $this->assertSame(8, $service->fresh()->max_file_size_mb);
    }

    public function test_the_printing_wizard_and_the_order_page_follow_the_limits(): void
    {
        $customer = User::factory()->create();
        $service = PrintingService::factory()->published()->create(['allowed_file_types' => ['pdf'], 'max_file_size_mb' => 10, 'base_price' => '5.00']);
        $this->save(['printing_max_file_mb' => 1, 'printing_max_total_mb' => 3, 'printing_max_files' => 2]);

        $this->actingAs($customer)->get(route('customer.printing.create'))->assertOk()
            ->assertViewHas('maxFiles', 2)->assertViewHas('maxTotalMb', 3)
            ->assertViewHas('rules', fn ($rules) => $rules[$service->id]['maxKb'] === 1024);

        $send = fn (array $files) => $this->actingAs($customer)->from(route('customer.printing.create'))->post(route('customer.printing.store'), [
            'service' => $service->id, 'quantity' => 1, 'pages' => 1, 'files' => $files,
        ]);

        $send([$this->pdf(2 * self::MB)])->assertSessionHasErrors(['files.0' => 'doc.pdf: The file is too large. The most this service accepts is 1 MB.']);
        $send([$this->pdf(self::MB), $this->pdf(self::MB), $this->pdf(self::MB)])->assertSessionHasErrors('files');
        $this->assertSame(0, PrintingOrder::query()->count());

        $send([$this->pdf(self::MB)])->assertSessionHasNoErrors();
        $this->assertSame(1, PrintingOrder::query()->count());
    }

    public function test_booking_images_and_the_booking_form_follow_the_limits(): void
    {
        $images = app(BookingImageService::class);
        $this->save(['booking_max_image_mb' => 1, 'booking_max_images' => 2, 'booking_max_total_mb' => 2]);
        $images = app(BookingImageService::class);

        $images->validate($this->jpeg(self::MB));
        $this->assertSame('The image is too large. The most is 1 MB per image.', $this->refused(fn () => $images->validate($this->jpeg(2 * self::MB))));
        $this->assertSame('A booking can have up to 2 reference images.', $this->refused(fn () => $images->validateSet([$this->jpeg(10), $this->jpeg(10), $this->jpeg(10)])));
        $this->assertStringContainsString('The most one booking can hold is 2 MB.', $this->refused(fn () => $images->validateSet([$this->jpeg(2 * self::MB), $this->jpeg(10)])));

        $package = PhotographyPackage::factory()->published()->create();
        $this->actingAs(User::factory()->create())->get(route('customer.bookings.create', ['package' => $package->slug]))->assertOk()
            ->assertViewHas('imageRules', fn ($rules) => $rules['max'] === 2 && $rules['maxMb'] === 1 && $rules['totalMb'] === 2
                && $rules['tooLarge'] === 'The image is too large. The most is 1 MB per image.');
    }

    public function test_the_service_form_follows_the_printing_limit(): void
    {
        $owner = User::factory()->owner()->create();
        $this->save(['printing_max_file_mb' => 4]);

        $data = fn (string $name, int $mb) => [
            'name' => $name, 'category' => 'Documents', 'pricing_type' => 'per_page', 'base_price' => '5.00',
            'allowed_file_types' => ['pdf'], 'max_file_size_mb' => $mb, 'sort_order' => 0,
        ];

        $this->actingAs($owner)->post(route('owner.services.store'), $data('Exact', 4))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('owner.services.store'), $data('Over', 5))->assertSessionHasErrors('max_file_size_mb');
        $this->assertSame(['Exact'], PrintingService::query()->pluck('name')->all());

        $this->actingAs($owner)->get(route('owner.services.create'))->assertOk()->assertViewHas('maxFileSize', 4)->assertSee('From 1 to 4 MB.');
    }

    public function test_lowering_a_limit_never_touches_old_orders_or_files(): void
    {
        $order = PrintingOrder::factory()->create();
        $file = OrderFile::query()->forceCreate([
            'printing_order_id' => $order->id, 'original_name' => 'big.pdf', 'stored_name' => 'x.pdf', 'path' => 'printing-orders/'.$order->id.'/x.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 9 * self::MB, 'uploaded_by' => $order->customer_id,
        ]);
        Storage::disk('local')->put($file->path, 'old');
        $booking = PhotographyBooking::factory()->status(BookingStatus::Requested)->create();
        $image = BookingReferenceImage::query()->forceCreate([
            'photography_booking_id' => $booking->id, 'original_name' => 'big.jpg', 'stored_name' => 'y.jpg', 'path' => 'booking-images/'.$booking->id.'/y.jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 4 * self::MB, 'uploaded_by' => $booking->customer_id,
        ]);

        $this->actingAs(User::factory()->owner()->create())->post(route('owner.settings.uploads'), [
            'printing_max_file_mb' => 1, 'printing_max_total_mb' => 1, 'printing_max_files' => 1, 'booking_max_image_mb' => 1, 'booking_max_images' => 1, 'booking_max_total_mb' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(9 * self::MB, $file->fresh()->size_bytes);
        $this->assertSame(4 * self::MB, $image->fresh()->size_bytes);
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame(1, $order->files()->count());
    }
}
