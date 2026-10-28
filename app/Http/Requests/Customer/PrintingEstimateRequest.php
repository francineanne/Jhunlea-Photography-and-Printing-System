<?php

namespace App\Http\Requests\Customer;

use App\Enums\PricingType;
use App\Models\PrintingService;
use App\Services\PricingService;
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
 */
class PrintingEstimateRequest extends FormRequest
{
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
            'pages' => [Rule::requiredIf(fn () => $this->service()?->pricing_type === PricingType::PerPage), 'nullable', 'integer', 'min:1', 'max:'.config('printing.max_pages')],
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
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['quantity' => 'number of copies', 'pages' => 'number of pages'];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['service', 'quantity', 'pages', 'options', 'options.*', 'options.*.*'])) {
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

    /** Pages count only for services priced per page; every other service is worked out on 1. */
    public function pages(): int
    {
        return $this->service()?->pricing_type === PricingType::PerPage ? (int) $this->input('pages') : 1;
    }

    /** Only set after validation passed. */
    public function estimate(): ?PriceEstimate
    {
        return $this->estimate;
    }
}
