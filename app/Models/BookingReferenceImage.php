<?php

namespace App\Models;

use Database\Factories\BookingReferenceImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Nothing is fillable: an image row is created only by BookingImageService, from a file it has already checked and stored.
class BookingReferenceImage extends Model
{
    /** @use HasFactory<BookingReferenceImageFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /** @return BelongsTo<PhotographyBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(PhotographyBooking::class, 'photography_booking_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
