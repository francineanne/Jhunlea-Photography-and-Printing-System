<?php

namespace Tests\Feature\Booking;

use App\Models\BookingReferenceImage;
use App\Models\PhotographyBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 4C: a booking's reference image, shown or downloaded only for the owner and the customer whose booking it is. */
class BookingImageRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private PhotographyBooking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->customer = User::factory()->create();
        $this->booking = PhotographyBooking::factory()->forCustomer($this->customer)->create();
    }

    private function jpegBytes(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(20, 20));

        return (string) ob_get_clean();
    }

    /** An image record whose stored file holds the given bytes. */
    private function image(string $bytes, array $attributes = []): BookingReferenceImage
    {
        $stored = ($attributes['stored_name'] ?? 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd.jpg');
        $path = "booking-images/{$this->booking->id}/{$stored}";
        Storage::disk('local')->put($path, $bytes);

        return BookingReferenceImage::factory()->create(array_replace([
            'photography_booking_id' => $this->booking->id,
            'stored_name' => $stored,
            'path' => $path,
            'original_name' => 'my "pose" ; photo.jpg',
            'uploaded_by' => $this->customer->id,
        ], $attributes));
    }

    public function test_the_owning_customer_and_the_owner_see_a_real_image_inline_with_safe_headers(): void
    {
        $image = $this->image($this->jpegBytes());

        foreach ([$this->customer, User::factory()->owner()->create()] as $viewer) {
            $response = $this->actingAs($viewer)->get(route('booking-images.show', $image))->assertOk();

            $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('my pose  photo.jpg', $response->headers->get('Content-Disposition'));
            $this->assertStringNotContainsString('booking-images', implode(' ', array_map(fn ($v) => implode(' ', $v), $response->headers->all())));
            $this->assertStringNotContainsString('AbCdEfGh', $response->headers->get('Content-Disposition'));
        }
    }

    public function test_the_type_comes_from_the_content_not_the_stored_column(): void
    {
        $image = $this->image($this->jpegBytes(), ['mime' => 'image/png']);

        $this->assertSame('image/jpeg', $this->actingAs($this->customer)->get(route('booking-images.show', $image))->headers->get('Content-Type'));
    }

    public function test_content_that_is_not_an_allowed_image_is_always_a_download(): void
    {
        foreach (['<html><script>alert(1)</script></html>', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'GIF89a......'] as $bytes) {
            $image = $this->image($bytes, ['mime' => 'image/jpeg']);
            $response = $this->actingAs($this->customer)->get(route('booking-images.show', $image))->assertOk();

            $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $image->delete();
        }
    }

    public function test_the_download_link_always_downloads(): void
    {
        $image = $this->image($this->jpegBytes());

        $response = $this->actingAs($this->customer)->get(route('booking-images.download', $image))->assertOk();

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_another_customer_and_guests_are_refused(): void
    {
        $image = $this->image($this->jpegBytes());
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('booking-images.show', $image))->assertForbidden();
        $this->actingAs($stranger)->get(route('booking-images.download', $image))->assertForbidden();
        auth()->logout();
        $this->get(route('booking-images.show', $image))->assertRedirect(route('login'));
    }

    public function test_a_missing_file_is_a_404_that_names_no_path(): void
    {
        $image = $this->image($this->jpegBytes());
        Storage::disk('local')->delete($image->path);

        $response = $this->actingAs($this->customer)->get(route('booking-images.show', $image))->assertNotFound();

        $this->assertStringNotContainsString('booking-images/', $response->getContent());
        $this->assertStringNotContainsString($image->stored_name, $response->getContent());
        $this->actingAs($this->customer)->get(route('booking-images.show', 999999))->assertNotFound();
    }

    public function test_path_tricks_never_reach_a_file(): void
    {
        $this->image($this->jpegBytes());

        foreach (['../.env', '..%2F..%2F.env', '%2e%2e%2f%2e%2e%2f.env', '1%2F..%2F..%2F.env', '..%5C..%5C.env', '1.jpg', '-1'] as $trick) {
            $this->actingAs($this->customer)->get('/booking-images/'.$trick)->assertNotFound();
            $this->actingAs($this->customer)->get('/booking-images/'.$trick.'/download')->assertNotFound();
        }
    }
}
