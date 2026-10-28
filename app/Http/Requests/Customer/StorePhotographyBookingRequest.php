<?php

namespace App\Http\Requests\Customer;

use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Services\AvailabilityService;
use App\Services\BookingImageService;
use App\Services\BookingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The whole booking wizard in one multipart POST: every step is checked again here, whatever the browser did. The
 * rules and their words belong to BookingService, BookingImageService and AvailabilityService; this request only asks
 * them, so every problem shows next to its field at once. BookingService::place() checks everything again when it saves.
 */
class StorePhotographyBookingRequest extends FormRequest
{
    /** The slot's start as the slot list and the no-script list send it: the UTC moment, e.g. 2026-11-05T02:00:00Z. */
    public const STARTS_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    public const PRINTS = ['digital', 'maybe'];

    // Words for a missing answer; the wizard shows the same ones before sending
    public const CHOOSE_PACKAGE = 'Choose a package.';

    public const ENTER_GROUP_SIZE = 'Enter how many people will be in the photos.';

    public const CHOOSE_SLOT = 'Choose a date and a time.';

    public const CHOOSE_PRINTS = 'Choose digital only or that you may want prints.';

    private ?PhotographyPackage $chosenPackage = null;

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:customer route middleware and the controller's policy check
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'package' => ['required', 'integer', Rule::exists('photography_packages', 'id')->where('is_published', true)->whereNull('deleted_at')],
            'group_size' => ['required', 'integer', 'min:1'],
            'contact_number' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:'.BookingService::NOTES_MAX],
            'starts_at' => ['required', 'string', 'date_format:'.self::STARTS_AT_FORMAT],
            'theme' => ['nullable', 'integer'],
            'custom_theme_text' => ['nullable', 'string', 'max:'.BookingService::CUSTOM_THEME_MAX],
            'prints' => ['required', Rule::in(self::PRINTS)],
            'images' => ['nullable', 'array'],
            'images.*' => ['file'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'package.required' => self::CHOOSE_PACKAGE,
            'package.integer' => self::CHOOSE_PACKAGE,
            'package.exists' => BookingService::PACKAGE_MESSAGE,
            'group_size.required' => self::ENTER_GROUP_SIZE,
            'group_size.integer' => self::ENTER_GROUP_SIZE,
            'group_size.min' => self::ENTER_GROUP_SIZE,
            'contact_number.required' => BookingService::CONTACT_NUMBER_MESSAGE,
            'contact_number.string' => BookingService::CONTACT_NUMBER_MESSAGE,
            'notes.max' => BookingService::NOTES_MESSAGE,
            'starts_at.required' => self::CHOOSE_SLOT,
            'starts_at.string' => 'Choose a date and a time from the list.',
            'starts_at.date_format' => 'Choose a date and a time from the list.',
            'theme.integer' => BookingService::THEME_MESSAGE,
            'custom_theme_text.max' => BookingService::CUSTOM_THEME_MESSAGE,
            'prints.required' => self::CHOOSE_PRINTS,
            'prints.in' => self::CHOOSE_PRINTS,
            'images.array' => 'One of the images did not arrive. Please choose your images again.',
            'images.*.file' => 'One of the images did not upload completely. Please choose it again.',
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $errors = $validator->errors();
            $package = $errors->has('package') ? null : $this->package();

            if ($package !== null && ! $errors->has('group_size') && $this->groupSize() > $package->max_people) {
                $errors->add('group_size', BookingService::groupSizeMessage($package));
            }

            if (! $errors->has('contact_number') && ! BookingService::isContactNumber($this->contactNumber())) {
                $errors->add('contact_number', BookingService::CONTACT_NUMBER_MESSAGE);
            }

            if (! $errors->has('theme') && $package !== null) {
                $theme = $this->theme();

                if ($this->filled('theme') && ($theme === null || ! BookingService::themeFits($package, $theme))) {
                    $errors->add('theme', BookingService::THEME_MESSAGE);
                } elseif ($theme === null && $this->customThemeText() === null) {
                    $errors->add('theme', BookingService::THEME_OR_IDEA_MESSAGE);
                }
            }

            $this->checkImages($validator);

            if ($package !== null && ! $errors->has('starts_at') && ! app(AvailabilityService::class)->isAvailable($package->duration_minutes, $this->startsAt())) {
                $errors->add('starts_at', BookingService::SLOT_TAKEN_MESSAGE);
            }

            if ($this->user() !== null && app(BookingService::class)->atRequestLimit($this->user())) {
                $errors->add('booking', BookingService::requestLimitMessage());
            }
        }];
    }

    /** The image rules (types by real content, sizes, how many, the total) are BookingImageService's own. */
    private function checkImages(Validator $validator): void
    {
        $images = app(BookingImageService::class);

        foreach ($this->uploads() as $index => $file) {
            // PHP drops a file bigger than its own limit before Laravel sees it: say so plainly
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $validator->errors()->add("images.{$index}", $file->getClientOriginalName().' is bigger than this server accepts ('.ini_get('upload_max_filesize').' per file).');

                continue;
            }

            if ($validator->errors()->has("images.{$index}")) {
                continue;
            }

            try {
                $images->validate($file);
            } catch (ValidationException $e) {
                $validator->errors()->add("images.{$index}", $file->getClientOriginalName().': '.collect($e->errors())->flatten()->first());
            }
        }

        try {
            $images->validateSet($this->uploads());
        } catch (ValidationException $e) {
            $validator->errors()->add('images', collect($e->errors())->flatten()->first());
        }
    }

    public function package(): ?PhotographyPackage
    {
        if ($this->chosenPackage === null && filter_var($this->input('package'), FILTER_VALIDATE_INT) !== false) {
            $this->chosenPackage = PhotographyPackage::published()->find((int) $this->input('package'));
        }

        return $this->chosenPackage;
    }

    public function theme(): ?Theme
    {
        return filter_var($this->input('theme'), FILTER_VALIDATE_INT) !== false ? Theme::query()->find((int) $this->input('theme')) : null;
    }

    public function customThemeText(): ?string
    {
        return $this->text('custom_theme_text');
    }

    /** Only read after validation passed, so the format is known to be right. */
    public function startsAt(): Carbon
    {
        return Carbon::createFromFormat(self::STARTS_AT_FORMAT, (string) $this->input('starts_at'), 'UTC');
    }

    public function groupSize(): int
    {
        return (int) $this->input('group_size');
    }

    public function contactNumber(): string
    {
        return trim((string) (is_string($this->input('contact_number')) ? $this->input('contact_number') : ''));
    }

    public function notes(): ?string
    {
        return $this->text('notes');
    }

    public function wantsPrints(): bool
    {
        return $this->input('prints') === 'maybe';
    }

    /** @return list<UploadedFile> */
    public function uploads(): array
    {
        return array_values(array_filter((array) $this->file('images', []), fn ($file) => $file instanceof UploadedFile));
    }

    private function text(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
