<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\AvailabilityRule;
use App\Models\BookingReferenceImage;
use App\Models\BookingSetting;
use App\Models\BookingStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\ReferenceNumberService;
use Database\Seeders\BookingScheduleSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_and_printing_reference_numbers_count_on_their_own(): void
    {
        $numbers = app(ReferenceNumberService::class);

        $this->assertSame('PR-000001', $numbers->next(ReferenceNumberService::PRINTING));
        $this->assertSame('PB-000001', $numbers->next(ReferenceNumberService::BOOKING));
        $this->assertSame('PB-000002', $numbers->next(ReferenceNumberService::BOOKING));
        $this->assertSame('PR-000002', $numbers->next(ReferenceNumberService::PRINTING));
    }

    public function test_status_price_customer_and_reference_cannot_be_mass_assigned(): void
    {
        $booking = new PhotographyBooking;
        $booking->fill([
            'status' => 'confirmed', 'customer_id' => 5, 'reference_number' => 'PB-1', 'estimated_price' => '1.00',
            'confirmed_price' => '1.00', 'starts_at' => now(), 'photography_package_id' => 1, 'rejection_reason' => 'x',
            'group_size' => 2, 'notes' => 'hi', 'custom_theme_text' => 'Garden', 'wants_prints' => true,
            'contact_number' => '0917', 'internal_notes' => 'x',
        ]);

        foreach (['status', 'customer_id', 'reference_number', 'estimated_price', 'confirmed_price', 'starts_at', 'photography_package_id', 'rejection_reason', 'contact_number', 'internal_notes'] as $guarded) {
            $this->assertNull($booking->getAttribute($guarded), "{$guarded} must not be fillable");
        }
        $this->assertSame(2, $booking->group_size);
        $this->assertSame('hi', $booking->notes);
        $this->assertTrue($booking->wants_prints);
    }

    public function test_image_and_history_rows_cannot_be_filled_at_all(): void
    {
        foreach ([new BookingReferenceImage, new BookingStatusHistory] as $model) {
            try {
                $model->fill(['path' => 'x']);
                $this->fail($model::class.' must be fully guarded');
            } catch (MassAssignmentException) {
                // expected
            }
        }

        $rule = new AvailabilityRule(['weekday' => 3, 'is_open' => true]);
        $this->assertNull($rule->weekday);
        $settings = new BookingSetting(['lock_version' => 99, 'buffer_minutes' => 10]);
        $this->assertNull($settings->lock_version);
        $this->assertSame(10, $settings->buffer_minutes);
    }

    public function test_relationships_and_soft_deleted_packages_still_resolve(): void
    {
        $booking = PhotographyBooking::factory()->create();
        BookingReferenceImage::factory()->create(['photography_booking_id' => $booking->id]);
        BookingStatusHistory::factory()->create(['photography_booking_id' => $booking->id]);
        $order = PrintingOrder::factory()->create();
        $order->forceFill(['photography_booking_id' => $booking->id])->save();

        $booking->package->delete();
        $booking = $booking->fresh();

        $this->assertNotNull($booking->package);
        $this->assertTrue($booking->customer->is(User::find($booking->customer_id)));
        $this->assertCount(1, $booking->images);
        $this->assertCount(1, $booking->statusHistories);
        $this->assertTrue($booking->printingOrders->first()->is($order));
        $this->assertTrue($order->fresh()->booking->is($booking));
        $this->assertSame(60, $booking->durationMinutes());
    }

    public function test_deleting_a_booking_leaves_its_printing_order_without_the_link(): void
    {
        $booking = PhotographyBooking::factory()->create();
        $order = PrintingOrder::factory()->create();
        $order->forceFill(['photography_booking_id' => $booking->id])->save();

        $booking->forceDelete();

        $this->assertNull($order->fresh()->photography_booking_id);
    }

    public function test_the_tables_have_the_columns_the_phase_needs(): void
    {
        $this->assertTrue(Schema::hasColumns('photography_bookings', [
            'reference_number', 'customer_id', 'photography_package_id', 'theme_id', 'custom_theme_text', 'snapshot',
            'starts_at', 'ends_at', 'requested_starts_at', 'group_size', 'contact_number', 'notes', 'internal_notes', 'wants_prints', 'status',
            'estimated_price', 'confirmed_price', 'rejection_reason', 'proposed_starts_at', 'proposed_note',
            'cancellation_reason', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('booking_reference_images', ['photography_booking_id', 'original_name', 'stored_name', 'path', 'mime', 'size_bytes', 'uploaded_by']));
        $this->assertTrue(Schema::hasColumns('booking_status_histories', ['photography_booking_id', 'from_status', 'to_status', 'event', 'changed_by', 'note', 'created_at']));
        $this->assertTrue(Schema::hasColumns('availability_rules', ['weekday', 'is_open', 'opens_at', 'closes_at']));
        $this->assertTrue(Schema::hasColumns('blocked_dates', ['date', 'reason', 'created_by']));
        $this->assertTrue(Schema::hasColumns('booking_settings', ['buffer_minutes', 'min_notice_hours', 'max_advance_days', 'concurrent_sessions', 'lock_version']));
        $this->assertTrue(Schema::hasColumn('printing_orders', 'photography_booking_id'));
    }

    public function test_the_schedule_seeder_fills_the_defaults_once_and_never_overwrites(): void
    {
        $this->seed(BookingScheduleSeeder::class);

        $this->assertSame(7, AvailabilityRule::count());
        $sunday = AvailabilityRule::query()->where('weekday', 0)->first();
        $this->assertFalse($sunday->is_open);
        $monday = AvailabilityRule::query()->where('weekday', 1)->first();
        $this->assertTrue($monday->is_open);
        $this->assertSame('09:00:00', $monday->opens_at);
        $this->assertSame('17:00:00', $monday->closes_at);
        $settings = BookingSetting::current();
        $this->assertSame([15, 24, 30, 1], [$settings->buffer_minutes, $settings->min_notice_hours, $settings->max_advance_days, $settings->concurrent_sessions]);

        // The owner changes things; seeding again keeps them
        $monday->update(['opens_at' => '10:00:00']);
        $settings->update(['buffer_minutes' => 30]);
        $this->seed(BookingScheduleSeeder::class);

        $this->assertSame(7, AvailabilityRule::count());
        $this->assertSame('10:00:00', AvailabilityRule::query()->where('weekday', 1)->value('opens_at'));
        $this->assertSame(30, BookingSetting::current()->buffer_minutes);
        $this->assertSame(0, PhotographyBooking::count());
    }

    public function test_the_status_rules(): void
    {
        $this->assertSame([BookingStatus::Confirmed, BookingStatus::Completed], BookingStatus::blocking());
        $this->assertTrue(BookingStatus::Requested->acceptsImages());
        $this->assertTrue(BookingStatus::Proposed->acceptsImages());
        $this->assertFalse(BookingStatus::Confirmed->acceptsImages());
        $this->assertTrue(BookingStatus::Confirmed->allowsPriceChange());
        $this->assertFalse(BookingStatus::Requested->allowsPriceChange());
    }

    public function test_the_config_keeps_uploads_inside_what_php_accepts_on_xampp(): void
    {
        $this->assertLessThanOrEqual(10, config('booking.max_image_size_mb'));
        $this->assertLessThan(40, config('booking.max_total_size_mb'));
        $this->assertSame(5, config('booking.max_images_per_booking'));
        $this->assertSame(3, config('booking.max_open_requests_per_customer'));
        $this->assertSame(['jpg', 'png', 'webp'], array_keys(config('booking.types')));
        $this->assertNotSame('public', config('booking.disk'));
    }
}
