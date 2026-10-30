<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\BookingReferenceImage;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Support\ShopSettings;
use App\Support\UploadCheck;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks and stores the reference images of a booking on the private disk. The disk and types live in
 * config/booking.php; the sizes and how many are read through ShopSettings; the checking itself is UploadCheck, shared with printing files. A stored image has a
 * generated name (hashName); the customer's own file name is kept only as text for display.
 */
class BookingImageService
{
    public function __construct(private readonly ShopSettings $settings) {}

    public function maxBytes(): int
    {
        return $this->settings->bookingMaxImageMb() * 1024 * 1024;
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        return collect(config('booking.types'))->pluck('extensions')->flatten()->unique()->values()->all();
    }

    /** @throws ValidationException */
    public function validate(UploadedFile $file): void
    {
        UploadCheck::file($file, config('booking.types'), $this->maxBytes(), $this->tooLargeMessage(), $this->wrongTypeMessage(), 'images');
    }

    // The words of the image rules, also shown by the booking wizard before anything is sent

    public function tooLargeMessage(): string
    {
        return 'The image is too large. The most is '.$this->settings->bookingMaxImageMb().' MB per image.';
    }

    public function wrongTypeMessage(): string
    {
        return 'Reference images must be '.strtoupper(implode(', ', array_keys(config('booking.types')))).' files.';
    }

    public function tooManyMessage(): string
    {
        return 'A booking can have up to '.$this->settings->bookingMaxImages().' reference images.';
    }

    /**
     * How many images and how big all together, counting the images the booking already has.
     *
     * @param  array<int, UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function validateSet(array $files, int $alreadyStored = 0, int $alreadyStoredBytes = 0): void
    {
        $max = $this->settings->bookingMaxImages();

        if ($alreadyStored + count($files) > $max) {
            throw ValidationException::withMessages(['images' => $this->tooManyMessage()]);
        }

        UploadCheck::total($files, $this->settings->bookingMaxTotalMb(), 'one booking', $alreadyStoredBytes, 'images');
    }

    /**
     * Stores one image for a booking and records it. The caller has checked the image (validate) and the set
     * (validateSet) first.
     *
     * @throws AuthorizationException when the uploader may not add images to this booking
     * @throws ValidationException when the image is not acceptable
     */
    public function store(PhotographyBooking $booking, UploadedFile $file, User $uploader): BookingReferenceImage
    {
        $this->authorize($booking, $uploader);
        $this->validate($file);

        $disk = Storage::disk(config('booking.disk'));
        $path = $disk->putFile(config('booking.directory').'/'.$booking->getKey(), $file);

        if ($path === false) {
            throw ValidationException::withMessages(['images' => 'The image could not be saved. Please try again.']);
        }

        try {
            $record = new BookingReferenceImage;
            $record->forceFill([
                'photography_booking_id' => $booking->getKey(),
                'original_name' => UploadCheck::displayName($file->getClientOriginalName()),
                'stored_name' => basename($path),
                'path' => $path,
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $uploader->getKey(),
            ])->save();
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        return $record;
    }

    private function authorize(PhotographyBooking $booking, User $uploader): void
    {
        if ($uploader->role !== UserRole::Owner && $booking->customer_id !== $uploader->id) {
            throw new AuthorizationException('This booking belongs to another customer.');
        }

        // The stored status counts, not a copy in memory
        $status = PhotographyBooking::query()->whereKey($booking->getKey())->first(['status'])?->status;

        if ($status === null || ! $status->acceptsImages()) {
            throw new AuthorizationException('Images can no longer be added to this booking.');
        }
    }
}
