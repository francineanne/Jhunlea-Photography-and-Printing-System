<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Places a booking REQUEST. Nothing is confirmed here: the studio confirms, proposes another time or rejects
 * (BookingTransitionService). A request never blocks the slot for other customers.
 *
 * Everything is checked first (package, theme, group size, every image, and that the time is still a free slot right
 * now), then the reference number, the booking, its images and the first history row are saved in one transaction.
 * If anything fails after an image was written, the transaction rolls back and every written image is deleted.
 */
class BookingService
{
    public const CUSTOM_THEME_MAX = 500;

    // The column is 30 characters: 20 digits plus room for spaces, dashes and brackets
    public const CONTACT_NUMBER_MAX = 30;

    public function __construct(
        private readonly ReferenceNumberService $references,
        private readonly AvailabilityService $availability,
        private readonly BookingTransitionService $transitions,
        private readonly BookingImageService $images,
    ) {}

    /**
     * @param  list<UploadedFile>  $uploads
     *
     * @throws AuthorizationException when the user is not a customer
     * @throws ValidationException when a choice is not valid (the key names the field)
     */
    public function place(
        User $customer,
        PhotographyPackage $package,
        ?Theme $theme,
        ?string $customThemeText,
        CarbonInterface $startsAt,
        int $groupSize,
        string $contactNumber,
        ?string $notes,
        bool $wantsPrints,
        array $uploads = [],
    ): PhotographyBooking {
        if ($customer->role !== UserRole::Customer) {
            throw new AuthorizationException('Only customers request photoshoots.');
        }

        if ($package->trashed() || ! $package->is_published) {
            $this->fail('package', 'This package is not available. Please choose another one.');
        }

        if ($theme !== null && ($theme->trashed() || ! $theme->is_published || ! $package->themes()->whereKey($theme->id)->exists())) {
            $this->fail('theme', 'This backdrop is not available for this package. Please choose another one.');
        }

        $customThemeText = $this->clean($customThemeText);

        if ($customThemeText !== null && mb_strlen($customThemeText) > self::CUSTOM_THEME_MAX) {
            $this->fail('custom_theme_text', 'Describe your own theme in up to '.self::CUSTOM_THEME_MAX.' characters.');
        }

        if ($groupSize < 1 || $groupSize > $package->max_people) {
            $this->fail('group_size', "This package is for 1 to {$package->max_people} ".($package->max_people === 1 ? 'person.' : 'people.'));
        }

        $contactNumber = trim($contactNumber);

        if (! self::isContactNumber($contactNumber)) {
            $this->fail('contact_number', 'Enter a phone number the studio can call, with 7 to 20 digits (for example 0917 123 4567).');
        }

        foreach ($uploads as $upload) {
            $this->images->validate($upload);
        }

        $this->images->validateSet($uploads);

        $start = Carbon::instance($startsAt)->utc();

        if (! $this->availability->isAvailable($package->duration_minutes, $start)) {
            $this->fail('starts_at', 'That time is no longer free. Please choose another time.');
        }

        $written = [];
        $bookingId = null;

        try {
            return DB::transaction(function () use ($customer, $package, $theme, $customThemeText, $start, $groupSize, $contactNumber, $notes, $wantsPrints, $uploads, &$written, &$bookingId) {
                // The customer's own row is locked first, so two requests sent at once are counted one after the other
                User::query()->lockForUpdate()->findOrFail($customer->getKey());

                $max = (int) config('booking.max_open_requests_per_customer');
                $open = PhotographyBooking::query()
                    ->where('customer_id', $customer->getKey())
                    ->whereIn('status', [BookingStatus::Requested, BookingStatus::Proposed])
                    ->count();

                if ($open >= $max) {
                    $this->fail('booking', "You already have {$max} requests waiting for the studio. Please wait for a reply or cancel one.");
                }

                $booking = new PhotographyBooking([
                    'group_size' => $groupSize,
                    'notes' => $this->clean($notes),
                    'custom_theme_text' => $customThemeText,
                    'wants_prints' => $wantsPrints,
                ]);
                $booking->forceFill([
                    'reference_number' => $this->references->next(ReferenceNumberService::BOOKING),
                    'customer_id' => $customer->getKey(),
                    'contact_number' => $contactNumber,
                    'photography_package_id' => $package->getKey(),
                    'theme_id' => $theme?->getKey(),
                    'snapshot' => $this->snapshot($package, $theme, $customThemeText),
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->addMinutes($package->duration_minutes),
                    'requested_starts_at' => $start,
                    'status' => BookingStatus::Requested,
                    'estimated_price' => $this->estimate($package),
                ])->save();
                $bookingId = $booking->getKey();

                $this->transitions->recordRequest($booking, $customer);

                foreach ($uploads as $upload) {
                    $written[] = $this->images->store($booking, $upload, $customer)->path;
                }

                return $booking;
            });
        } catch (Throwable $e) {
            $disk = Storage::disk(config('booking.disk'));
            $disk->delete($written);

            // A rolled-back booking id is not handed out again, so its folder holds nothing else; it goes when empty
            if ($bookingId !== null && $disk->allFiles(config('booking.directory').'/'.$bookingId) === []) {
                $disk->deleteDirectory(config('booking.directory').'/'.$bookingId);
            }

            throw $e;
        }
    }

    /**
     * The three ways a package is priced, as PhotographyPackage::priceText() words them: a fixed price, a starting
     * price the studio confirms ("from"), or no price ("on request", no estimate).
     */
    public static function priceCase(PhotographyPackage $package): string
    {
        return match (true) {
            $package->price === null => 'on_request',
            $package->price_requires_confirmation => 'from',
            default => 'fixed',
        };
    }

    /** 7 to 20 digits; spaces, +, - and parentheses may sit between them. Nothing else. */
    public static function isContactNumber(string $number): bool
    {
        if (mb_strlen($number) > self::CONTACT_NUMBER_MAX || preg_match('/^[0-9 +\-()]+$/', $number) !== 1) {
            return false;
        }

        $digits = strlen((string) preg_replace('/\D/', '', $number));

        return $digits >= 7 && $digits <= 20;
    }

    private function estimate(PhotographyPackage $package): ?string
    {
        return $package->price;
    }

    /** @return array<string, mixed> */
    private function snapshot(PhotographyPackage $package, ?Theme $theme, ?string $customThemeText): array
    {
        return [
            'package' => [
                'id' => $package->id,
                'name' => $package->name,
                'category' => $package->category,
                'duration_minutes' => $package->duration_minutes,
                'max_people' => $package->max_people,
                'digital_outputs' => $package->digital_outputs,
                'price' => $package->price,
                'price_case' => self::priceCase($package),
                'price_text' => $package->priceText(),
                'inclusions' => array_values($package->inclusions ?? []),
            ],
            'theme' => $theme ? ['id' => $theme->id, 'name' => $theme->name] : null,
            'custom_theme_text' => $customThemeText,
        ];
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
