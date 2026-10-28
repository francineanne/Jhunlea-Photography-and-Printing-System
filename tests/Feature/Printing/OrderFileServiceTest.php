<?php

namespace Tests\Feature\Printing;

use App\Enums\PrintingOrderStatus as Status;
use App\Models\OrderFile;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\OrderFileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class OrderFileServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private User $customer;

    private PrintingOrder $order;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $service = PrintingService::factory()->published()->create(['allowed_file_types' => ['pdf', 'jpg', 'png'], 'max_file_size_mb' => 2]);
        $this->customer = User::factory()->create();
        $this->order = PrintingOrder::factory()->forCustomer($this->customer)->create(['printing_service_id' => $service->id]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** A real file on disk, so the server reads its bytes the way it does for a true upload. */
    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function store(UploadedFile $file, ?User $by = null, ?PrintingOrder $order = null): OrderFile
    {
        return app(OrderFileService::class)->store($order ?? $this->order, $file, $by ?? $this->customer);
    }

    private function assertRefused(UploadedFile $file): void
    {
        try {
            $this->store($file);
            $this->fail('The file should have been refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }

        $this->assertSame(0, OrderFile::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    private function jpeg(): string
    {
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
    }

    public function test_a_valid_pdf_is_stored_on_the_private_disk_under_a_generated_name(): void
    {
        $file = $this->store($this->upload('Thesis chapter 1.pdf', self::PDF));

        $this->assertSame('Thesis chapter 1.pdf', $file->original_name);
        $this->assertSame('application/pdf', $file->mime);
        $this->assertSame(strlen(self::PDF), $file->size_bytes);
        $this->assertSame($this->customer->id, $file->uploaded_by);
        $this->assertSame($this->order->id, $file->printing_order_id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.pdf$/', $file->stored_name);
        $this->assertSame("printing-orders/{$this->order->id}/{$file->stored_name}", $file->path);
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_the_disk_is_private_and_never_the_public_one(): void
    {
        $this->assertSame('local', config('printing.disk'));
        $this->assertNotSame('public', config('printing.disk'));

        $root = realpath(config('filesystems.disks.'.config('printing.disk').'.root')) ?: config('filesystems.disks.'.config('printing.disk').'.root');

        $this->assertStringStartsNotWith(realpath(public_path()) ?: public_path(), $root);
        $this->assertNull(config('filesystems.disks.'.config('printing.disk').'.url'));
        $this->assertNotSame('public', config('filesystems.disks.'.config('printing.disk').'.visibility'));
    }

    public function test_png_and_jpeg_files_are_accepted_and_jpeg_may_end_in_jpeg(): void
    {
        $this->assertSame('image/png', $this->store($this->upload('a.png', $this->png()))->mime);
        $this->assertSame('image/jpeg', $this->store($this->upload('b.jpg', $this->jpeg()))->mime);
        $this->assertSame('image/jpeg', $this->store($this->upload('c.JPEG', $this->jpeg()))->mime);
    }

    public function test_the_same_file_twice_gets_two_different_stored_names(): void
    {
        $a = $this->store($this->upload('same.pdf', self::PDF));
        $b = $this->store($this->upload('same.pdf', self::PDF));

        $this->assertNotSame($a->stored_name, $b->stored_name);
    }

    public function test_a_program_renamed_to_pdf_is_refused_because_its_bytes_say_otherwise(): void
    {
        $this->assertRefused($this->upload('invoice.pdf', '<?php echo shell_exec($_GET["c"]);'));
        $this->assertRefused($this->upload('photo.jpg', self::PDF));
        $this->assertRefused($this->upload('photo.png', $this->jpeg()));
    }

    public function test_extensions_the_service_does_not_accept_are_refused(): void
    {
        $this->assertRefused($this->upload('run.php', '<?php echo 1;'));
        $this->assertRefused($this->upload('run.exe', "MZ\x90\x00"));
        $this->assertRefused($this->upload('page.html', '<html></html>'));
        $this->assertRefused($this->upload('noextension', self::PDF));
        $this->assertRefused($this->upload('doc.docx', self::PDF));
    }

    public function test_a_double_extension_is_judged_by_the_last_part(): void
    {
        $this->assertRefused($this->upload('shell.pdf.php', self::PDF));
        $this->assertRefused($this->upload('shell.php.pdf', '<?php echo 1;'));
    }

    public function test_a_file_over_the_service_limit_is_refused(): void
    {
        $this->assertRefused($this->upload('big.pdf', self::PDF.str_repeat('0', 2 * 1024 * 1024)));
    }

    public function test_a_file_exactly_at_the_limit_is_accepted(): void
    {
        $contents = self::PDF.str_repeat('0', 2 * 1024 * 1024 - strlen(self::PDF));

        $this->assertSame(2 * 1024 * 1024, $this->store($this->upload('exact.pdf', $contents))->size_bytes);
    }

    public function test_the_system_wide_limit_beats_a_larger_service_limit(): void
    {
        config(['printing.max_file_size_mb' => 1]);

        $this->assertSame(1024, app(OrderFileService::class)->maxKilobytes($this->order->service));
        $this->assertRefused($this->upload('big.pdf', self::PDF.str_repeat('0', 1024 * 1024)));
    }

    public function test_an_empty_file_is_refused(): void
    {
        $this->assertRefused($this->upload('empty.pdf', ''));
    }

    public function test_a_failed_upload_is_refused(): void
    {
        $file = new UploadedFile($this->upload('x.pdf', self::PDF)->getPathname(), 'x.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL, true);

        $this->assertRefused($file);
    }

    public function test_an_order_holds_only_so_many_files(): void
    {
        config(['printing.max_files_per_order' => 2]);

        $this->store($this->upload('1.pdf', self::PDF));
        $this->store($this->upload('2.pdf', self::PDF));

        $this->expectException(ValidationException::class);

        $this->store($this->upload('3.pdf', self::PDF));
    }

    public function test_folders_and_control_characters_in_the_customers_file_name_never_reach_the_path(): void
    {
        $file = $this->store($this->upload("../../etc/pass\x00wd\n.pdf", self::PDF));

        $this->assertStringNotContainsString('..', $file->path);
        $this->assertStringNotContainsString('/', $file->original_name);
        $this->assertStringNotContainsString("\x00", $file->original_name);
        $this->assertStringStartsWith("printing-orders/{$this->order->id}/", $file->path);
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_a_very_long_file_name_is_cut_for_display_only(): void
    {
        $file = $this->store($this->upload(str_repeat('a', 400).'.pdf', self::PDF));

        $this->assertLessThanOrEqual(255, mb_strlen($file->original_name));
    }

    public function test_another_customer_cannot_add_a_file_to_this_order(): void
    {
        $this->expectException(AuthorizationException::class);

        try {
            $this->store($this->upload('x.pdf', self::PDF), User::factory()->create());
        } finally {
            $this->assertSame(0, OrderFile::count());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_the_owner_can_add_a_file(): void
    {
        $owner = User::factory()->owner()->create();

        $this->assertSame($owner->id, $this->store($this->upload('x.pdf', self::PDF), $owner)->uploaded_by);
    }

    public function test_no_file_can_be_added_once_the_order_is_being_processed(): void
    {
        $order = PrintingOrder::factory()->forCustomer($this->customer)->status(Status::Processing)->create(['printing_service_id' => $this->order->printing_service_id]);

        $this->expectException(AuthorizationException::class);

        $this->store($this->upload('x.pdf', self::PDF), null, $order);
    }

    public function test_the_stored_status_decides_not_a_stale_copy_in_memory(): void
    {
        $stale = PrintingOrder::find($this->order->id);
        PrintingOrder::whereKey($this->order->id)->update(['status' => Status::Accepted->value]);

        $this->expectException(AuthorizationException::class);

        $this->store($this->upload('x.pdf', self::PDF), null, $stale);
    }

    public function test_the_extensions_a_service_accepts_come_from_the_one_config(): void
    {
        $service = PrintingService::factory()->make(['allowed_file_types' => ['pdf', 'jpg']]);

        $this->assertEqualsCanonicalizing(['pdf', 'jpg', 'jpeg'], app(OrderFileService::class)->allowedExtensions($service));
    }

    public function test_office_files_are_accepted_when_they_are_real_zip_packages_and_refused_when_they_are_not(): void
    {
        $service = PrintingService::factory()->published()->create(['allowed_file_types' => ['docx'], 'max_file_size_mb' => 2]);
        $order = PrintingOrder::factory()->forCustomer($this->customer)->create(['printing_service_id' => $service->id]);

        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        $this->tempFiles[] = $zipPath;
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->close();

        $stored = $this->store($this->upload('letter.docx', file_get_contents($zipPath)), null, $order);
        $this->assertSame('letter.docx', $stored->original_name);

        $this->expectException(ValidationException::class);

        $this->store($this->upload('fake.docx', 'just some text, not a package'), null, $order);
    }

    public function test_a_file_row_cannot_be_filled_from_a_request(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new OrderFile)->fill(['path' => '../../outside', 'printing_order_id' => 1]);
    }
}
