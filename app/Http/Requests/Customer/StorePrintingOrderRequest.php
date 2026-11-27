<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\ValidatesOrderFiles;
use App\Models\PhotographyBooking;
use App\Services\PrintingOrderService;
use App\Support\PdfPageCounter;
use App\Support\ShopSettings;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * The whole wizard in one multipart POST: every step is checked again here, whatever the browser did. The file rules
 * (types, sizes, real content) are OrderFileService's own, so they are written in one place only.
 */
class StorePrintingOrderRequest extends PrintingEstimateRequest
{
    use ValidatesOrderFiles;

    public const NOTES_MAX = 2000;

    private ?int $pdfPages = null;

    private bool $pdfPagesCounted = false;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + $this->fileRules(app(ShopSettings::class)->printingMaxFiles()) + [
            'notes' => ['nullable', 'string', 'max:'.self::NOTES_MAX],
            // Prints after the shoot: the customer's own completed booking, checked again by PrintingOrderService
            'booking' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + $this->fileMessages() + [
            'notes.max' => 'Special instructions can be up to :max characters.',
            'booking.integer' => PrintingOrderService::BOOKING_MESSAGE,
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [
            // First, so the estimate is never tried with more pages than an order may hold
            fn (Validator $validator) => $this->checkPdfPageLimit($validator),
            ...parent::after(),
            fn (Validator $validator) => $this->checkFiles($validator, $this->service()),
            fn (Validator $validator) => $this->checkCustomPages($validator),
            function (Validator $validator) {
                if ($this->filled('booking') && ! $validator->errors()->has('booking') && $this->attachedBooking() === null) {
                    $validator->errors()->add('booking', PrintingOrderService::BOOKING_MESSAGE);
                }
            },
        ];
    }

    /** The booking the prints are for: only the signed-in customer's own completed photoshoot, else null. */
    public function attachedBooking(): ?PhotographyBooking
    {
        $id = filter_var($this->input('booking'), FILTER_VALIDATE_INT);

        if ($id === false || $this->user() === null) {
            return null;
        }

        $booking = PhotographyBooking::query()->where('customer_id', $this->user()->id)->find($id);

        return $booking !== null && $booking->status->allowsPrintOrders() ? $booking : null;
    }

    /**
     * The customer's instructions. Phase 10R: with Custom pages the first line is "Pages to print: 1-5, 8", so the shop
     * sees the range without a new column.
     */
    public function notes(): ?string
    {
        $notes = $this->input('notes');
        $notes = is_string($notes) ? $notes : null;
        $line = $this->pagesLine();

        if ($line === null) {
            return $notes;
        }

        return $notes === null || trim($notes) === '' ? $line : $line."\n".trim($notes);
    }

    /** "Pages to print: 1-5, 8" for Custom pages on a per-page service, else null. */
    public function pagesLine(): ?string
    {
        $range = $this->perPage() && $this->customPages() ? $this->pageRange() : null;

        return $range === null ? null : 'Pages to print: '.$range->text;
    }

    /**
     * Phase 10R: the pages of the uploaded files, counted by the server: the sum when EVERY file is a PDF whose pages it
     * can read, else null (the customer types the number). Counted once per request.
     */
    public function knownPdfPages(): ?int
    {
        if ($this->pdfPagesCounted) {
            return $this->pdfPages;
        }

        $this->pdfPagesCounted = true;
        $uploads = $this->uploads();

        if ($uploads === []) {
            return $this->pdfPages = null;
        }

        $counter = app(PdfPageCounter::class);
        $total = 0;

        foreach ($uploads as $file) {
            $path = $file->getRealPath();
            $count = strtolower($file->getClientOriginalExtension()) === 'pdf' && $file->isValid() && is_string($path) ? $counter->count($path) : null;

            if ($count === null) {
                return $this->pdfPages = null;
            }

            $total += $count;
        }

        return $this->pdfPages = $total;
    }

    /**
     * All pages: the counted PDFs may not hold more pages than one online order allows (config printing.max_pages).
     * The error goes on the files, in step 3, and the estimate is not tried (PricingService would refuse the count).
     * Custom pages are not affected: the range itself stays within the limit.
     */
    private function checkPdfPageLimit(Validator $validator): void
    {
        $max = (int) config('printing.max_pages');
        $total = $this->perPage() && ! $this->customPages() ? $this->knownPdfPages() : null;

        if ($total !== null && $total > $max) {
            $validator->errors()->add('files', "Your files have {$total} pages. Online orders can be up to {$max} pages. For more, please talk to the shop.");
        }
    }

    /** Custom pages against the counted PDFs, and room in the notes for the "Pages to print" line. */
    private function checkCustomPages(Validator $validator): void
    {
        if (! $this->perPage() || ! $this->customPages() || $validator->errors()->has('page_range') || ($range = $this->pageRange()) === null) {
            return;
        }

        $total = $this->knownPdfPages();

        if ($total !== null && count($this->uploads()) === 1 && $range->highest() > $total) {
            $validator->errors()->add('page_range', "This PDF has {$total} ".Str::plural('page', $total).". Choose pages from 1 to {$total}.");
        } elseif ($total !== null && $range->count() > $total) {
            $validator->errors()->add('page_range', "Your files have {$total} pages in total. Choose at most {$total} pages.");
        }

        $notes = $this->input('notes');
        $room = self::NOTES_MAX - mb_strlen((string) $this->pagesLine()) - 1;

        if (is_string($notes) && ! $validator->errors()->has('notes') && mb_strlen(trim($notes)) > $room) {
            $validator->errors()->add('notes', "With custom pages, special instructions can be up to {$room} characters.");
        }
    }
}
