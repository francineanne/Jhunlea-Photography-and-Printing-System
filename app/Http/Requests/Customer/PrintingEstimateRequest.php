<?php

namespace App\Http\Requests\Customer;

use App\Enums\PricingType;
use App\Models\PrintingService;
use App\Services\PricingService;
use App\Support\PageRange;
use App\Support\PriceEstimate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * The service, options, copies and pages of a printing order. Used on its own for the live estimate, and as the base
 * of StorePrintingOrderRequest. The price rules themselves live in PricingService; this request only collects the
 * input and turns a refusal from PricingService into a message next to the right field.
 *
 * Options arrive as options[service id][group] = option id, so choices made under another service are simply not read.
 *
 * Phase 10R: pages for a per-page service are "All pages" (page_mode all, the default) or "Custom pages" (page_mode
 * custom with page_range such as "1-5, 8", counted by PageRange). With All pages the count is the PDFs' own count when
 * the server can read it (StorePrintingOrderRequest::knownPdfPages()); otherwise the customer types it, as before.
 */
class PrintingEstimateRequest extends FormRequest
{
    public const PAGE_MODES = ['all', 'custom'];

    public const RANGE_INVALID = 'Enter the pages like 1-5, 8: page numbers from 1, commas between them, and a dash for a range such as 2-4.';

    public const UNKNOWN_PAGES_HINT = 'We could not count the pages of this file. Enter the number of pages; the shop will check it.';

    private ?PrintingService $chosenService = null;

    private ?PriceEstimate $estimate = null;

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:customer route middleware
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'service' => ['required', 'integer', Rule::exists('printing_services', 'id')->where('is_published', true)->whereNull('deleted_at')],
            'options' => ['nullable', 'array'],
            'options.*' => ['nullable', 'array'],
            'options.*.*' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('printing.max_quantity')],
            'page_mode' => ['nullable', Rule::in(self::PAGE_MODES)],
            'page_range' => [Rule::requiredIf(fn () => $this->perPage() && $this->customPages()), 'nullable', 'string', 'max:'.PageRange::MAX_LENGTH],
            // Typed only when it is needed: All pages and no count the server can read. Otherwise it is not read at all
            'pages' => $this->pagesNeedTyping()
                ? ['required', 'integer', 'min:1', 'max:'.config('printing.max_pages')]
                : ['nullable'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'service.required' => 'Choose a printing service.',
            'service.exists' => 'This printing service is not available. Please choose another one.',
            'quantity.required' => 'Enter how many copies you need.',
            'quantity.min' => 'You need at least 1 copy.',
            'quantity.max' => 'You can order up to :max copies at once. For more, please talk to the shop.',
            'pages.required' => 'Enter the number of pages in your file.',
            'pages.min' => 'Your file needs at least 1 page.',
            'pages.max' => 'You can order up to :max pages at once. For more, please talk to the shop.',
            'page_mode.in' => 'Choose All pages or Custom pages.',
            'page_range.required' => 'Enter the pages to print, for example 1-5, 8.',
            'page_range.string' => self::RANGE_INVALID,
            'page_range.max' => 'The pages to print can be up to :max characters.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['quantity' => 'number of copies', 'pages' => 'number of pages', 'page_range' => 'pages to print'];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            // Custom pages: the text must be a real list of pages before anything is counted
            if ($this->perPage() && $this->customPages() && ! $validator->errors()->has('page_range') && $this->pageRange() === null) {
                $validator->errors()->add('page_range', self::RANGE_INVALID);
            }
        }, function (Validator $validator) {
            if ($validator->errors()->hasAny(['service', 'quantity', 'pages', 'page_mode', 'page_range', 'files', 'options', 'options.*', 'options.*.*'])) {
                return;
            }

            $service = $this->service();

            try {
                $this->estimate = app(PricingService::class)->estimate($service, array_values($this->chosenOptionIds()), $this->quantity(), $this->pages());
            } catch (InvalidArgumentException $e) {
                $validator->errors()->add('options', $e->getMessage());

                return;
            }

            // A group the owner set up for this service needs one choice, so the estimate never guesses a paper size.
            // The groups are taken from the options themselves, not from the names the form sent them under
            $picked = collect($this->estimate->options)->pluck('group')->all();
            $groups = $service->options()->active()->get()->map(fn ($option) => $option->group)->unique(fn ($group) => $group->value);

            foreach ($groups as $group) {
                if (! in_array($group->value, $picked, true)) {
                    $validator->errors()->add("options.{$group->value}", 'Choose a '.strtolower($group->label()).'.');
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                $this->estimate = null;
            }
        }];
    }

    public function service(): ?PrintingService
    {
        if ($this->chosenService === null && filter_var($this->input('service'), FILTER_VALIDATE_INT) !== false) {
            $this->chosenService = PrintingService::published()->find((int) $this->input('service'));
        }

        return $this->chosenService;
    }

    /** @return array<string, int> group => option id, read only from the chosen service's part of the form */
    public function chosenOptionIds(): array
    {
        $service = $this->service();
        $picked = $service ? $this->input('options.'.$service->id, []) : [];

        return collect(is_array($picked) ? $picked : [])
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false)
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function quantity(): int
    {
        return (int) $this->input('quantity');
    }

    /**
     * Pages count only for services priced per page; every other service is worked out on 1. Custom pages: the
     * range's count. All pages: the PDFs' own count when known (whatever number the browser sent), else the typed one.
     */
    public function pages(): int
    {
        if (! $this->perPage()) {
            return 1;
        }

        if ($this->customPages()) {
            return $this->pageRange()?->count() ?? 1;
        }

        return $this->knownPdfPages() ?? (int) $this->input('pages');
    }

    public function perPage(): bool
    {
        return $this->service()?->pricing_type === PricingType::PerPage;
    }

    public function customPages(): bool
    {
        return $this->input('page_mode') === 'custom';
    }

    public function pageRange(): ?PageRange
    {
        return PageRange::parse($this->input('page_range'));
    }

    /** The total page count of the uploaded files when every one is a PDF the server can count; the estimate has no files. */
    public function knownPdfPages(): ?int
    {
        return null;
    }

    /** The customer must type the number of pages: a per-page service, All pages, and no count the server can read. */
    public function pagesNeedTyping(): bool
    {
        return $this->perPage() && ! $this->customPages() && $this->knownPdfPages() === null;
    }

    /** Only set after validation passed. */
    public function estimate(): ?PriceEstimate
    {
        return $this->estimate;
    }
}
