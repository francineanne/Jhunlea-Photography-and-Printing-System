<?php

namespace App\Models;

use App\Enums\BookingEvent;
use App\Enums\BookingStatus;
use Database\Factories\BookingStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Written once by BookingTransitionService and never edited, so it has no updated_at and nothing is fillable.
class BookingStatusHistory extends Model
{
    /** @use HasFactory<BookingStatusHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => BookingStatus::class,
            'to_status' => BookingStatus::class,
            'event' => BookingEvent::class,
        ];
    }

    /** What happened, in a few words: the event, the first row of the booking, or the status it moved to. */
    public function title(): string
    {
        return match (true) {
            $this->event !== null => $this->event->label(),
            $this->from_status === null => 'Booking requested',
            default => $this->to_status->label(),
        };
    }

    /** @return BelongsTo<PhotographyBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(PhotographyBooking::class, 'photography_booking_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
