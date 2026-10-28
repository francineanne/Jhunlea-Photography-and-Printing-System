<?php

namespace Tests\Feature;

use App\Http\Controllers\OrderFileDownloadController;
use App\Models\OrderFile;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderFileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private OrderFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->alice = User::factory()->create();
        $order = PrintingOrder::factory()->forCustomer($this->alice)->create();
        $this->file = $this->storedFile($order, 'Thesis final.pdf', 'abcdefghijklmnopqrstuvwxyz0123456789ABCD.pdf', "%PDF-1.4\nhello\n%%EOF\n");
    }

    private function storedFile(PrintingOrder $order, string $original, string $stored, string $contents, string $mime = 'application/pdf'): OrderFile
    {
        $path = "printing-orders/{$order->id}/{$stored}";
        Storage::disk('local')->put($path, $contents);

        return OrderFile::factory()->create([
            'printing_order_id' => $order->id,
            'original_name' => $original,
            'stored_name' => $stored,
            'path' => $path,
            'mime' => $mime,
            'size_bytes' => strlen($contents),
            'uploaded_by' => $order->customer_id,
        ]);
    }

    public function test_the_customer_who_sent_it_can_download_it(): void
    {
        $response = $this->actingAs($this->alice)->get(route('files.download', $this->file));

        $response->assertOk();
        $this->assertSame("%PDF-1.4\nhello\n%%EOF\n", $response->streamedContent());
    }

    public function test_the_owner_can_download_it(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get(route('files.download', $this->file))->assertOk();
    }

    public function test_another_customer_gets_403_and_a_guest_is_sent_to_login(): void
    {
        $this->actingAs(User::factory()->create())->get(route('files.download', $this->file))->assertForbidden();
        auth()->logout();
        $this->get(route('files.download', $this->file))->assertRedirect('/login');
    }

    public function test_a_file_of_a_deleted_order_is_kept_from_the_customer(): void
    {
        $this->file->order->delete();

        $this->actingAs($this->alice)->get(route('files.download', $this->file))->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->get(route('files.download', $this->file))->assertOk();
    }

    public function test_the_headers_force_a_safe_download(): void
    {
        $response = $this->actingAs($this->alice)->get(route('files.download', $this->file));

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Thesis final.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_the_path_and_stored_name_never_reach_the_visitor(): void
    {
        $response = $this->actingAs($this->alice)->get(route('files.download', $this->file));

        $headers = (string) $response->headers;
        $this->assertStringNotContainsString($this->file->stored_name, $headers);
        $this->assertStringNotContainsString('printing-orders', $headers);
    }

    public function test_an_office_file_is_still_sent_as_a_neutral_type(): void
    {
        $file = $this->storedFile($this->file->order, 'Letter.docx', 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz.docx', "PK\x03\x04", 'application/zip');

        $response = $this->actingAs($this->alice)->get(route('files.download', $file));

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Letter.docx', $response->headers->get('Content-Disposition'));
    }

    public function test_a_missing_file_is_a_404_without_saying_where(): void
    {
        Storage::disk('local')->delete($this->file->path);

        $response = $this->actingAs($this->alice)->get(route('files.download', $this->file));

        $response->assertNotFound();
        $this->assertStringNotContainsString('printing-orders', $response->getContent());
        $this->assertStringNotContainsString($this->file->stored_name, $response->getContent());
    }

    /** @return array<string, array{string}> */
    public static function traversal(): array
    {
        return [
            'dot dot' => ['/files/../../.env'],
            'encoded' => ['/files/%2e%2e%2f%2e%2e%2f.env'],
            'double encoded' => ['/files/%252e%252e%252f.env'],
            'backslash' => ['/files/..%5c..%5c.env'],
            'path after id' => ['/files/1/../../.env'],
            'not a number' => ['/files/abc'],
            'id with path' => ['/files/1%2f..%2f..%2f.env'],
        ];
    }

    #[DataProvider('traversal')]
    public function test_no_path_can_be_reached_through_the_route(string $url): void
    {
        $response = $this->actingAs($this->alice)->get($url);

        $this->assertContains($response->getStatusCode(), [404, 400]);
        $this->assertStringNotContainsString('APP_KEY', $response->getContent());
    }

    /** @return array<string, array{string, string}> */
    public static function hostileNames(): array
    {
        return [
            'quotes and header break' => ["evil\"\r\nX-Injected: yes.pdf", 'evilX-Injected yes.pdf'],
            'folders' => ['../../etc/passwd.pdf', 'passwd.pdf'],
            'windows folders' => ['..\\..\\boot.pdf', 'boot.pdf'],
            'claims another type' => ['run.exe', 'run.pdf'],
            'only dots' => ['....', 'file.pdf'],
            'empty' => ['', 'file.pdf'],
            'unicode' => ['Résumé – final.pdf', 'Résumé – final.pdf'],
        ];
    }

    #[DataProvider('hostileNames')]
    public function test_the_download_name_is_cleaned(string $original, string $expected): void
    {
        $file = OrderFile::factory()->make(['original_name' => $original, 'stored_name' => 'abcdefghijklmnopqrstuvwxyz0123456789ABCD.pdf']);

        $this->assertSame($expected, OrderFileDownloadController::safeName($file));
    }

    public function test_a_hostile_name_cannot_break_the_header(): void
    {
        $this->file->forceFill(['original_name' => "evil\"\r\nSet-Cookie: x=1.pdf"])->save();

        $response = $this->actingAs($this->alice)->get(route('files.download', $this->file));

        $response->assertOk();
        $this->assertStringNotContainsString('x=1', implode(' ', $response->headers->all('set-cookie')));
        $this->assertStringNotContainsString("\n", $response->headers->get('Content-Disposition'));
    }

    public function test_downloads_are_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->alice)->get(route('files.download', $this->file));
        }

        $this->actingAs($this->alice)->get(route('files.download', $this->file))->assertTooManyRequests();
    }
}
