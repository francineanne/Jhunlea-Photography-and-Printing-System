<?php

namespace App\Http\Requests\Owner\WalkIn;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\PrintingService;
use App\Services\WalkIn\WalkInOrderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A walk-in printing order with its full payment (Phase 8A). WalkInOrderService and PaymentService check everything
 * again; the price is the catalog price or the owner's typed price, never a total sent by the browser.
 */
class StoreWalkInOrderRequest extends WalkInRequest
{
    protected function getRedirectUrl(): string
    {
        return route('owner.orders.walk-in.create');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->customerRules() + [
            'service' => ['required', 'integer', 'min:1'],
            'options' => ['nullable', 'array'],
            'options.*' => ['nullable', 'array'],
            'options.*.*' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('printing.max_quantity')],
            'pages' => ['nullable', 'integer', 'min:1', 'max:'.config('printing.max_pages')],
            'notes' => ['nullable', 'string', 'max:'.WalkInOrderService::NOTE_MAX],
            'price_choice' => ['required', 'string', Rule::in([WalkInOrderService::PRICE_CATALOG, WalkInOrderService::PRICE_CUSTOM])],
            'custom_price' => ['nullable', 'string', 'required_if:price_choice,'.WalkInOrderService::PRICE_CUSTOM, 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'price_note' => ['nullable', 'string', 'max:'.WalkInOrderService::PRICE_NOTE_MAX],
            'method' => ['required', 'string', Rule::enum(PaymentMethod::class)],
            'gcash_reference' => ['nullable', 'string', 'required_if:method,'.PaymentMethod::Gcash->value, 'regex:'.Payment::GCASH_REFERENCE_PATTERN],
            'cash_tendered' => ['nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'collected' => ['accepted'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->customerMessages() + [
            'service.*' => 'Choose a printing service.',
            'options.*' => 'Choose the options again.',
            'quantity.*' => 'Enter how many copies (1 to '.config('printing.max_quantity').').',
            'pages.*' => 'Enter the number of pages (1 to '.config('printing.max_pages').').',
            'notes.*' => 'Keep the note to '.WalkInOrderService::NOTE_MAX.' characters.',
            'price_choice.*' => 'Choose the catalog price or type a price.',
            'custom_price.*' => 'Type the price, for example 150 or 150.50.',
            'price_note.*' => 'Keep the price note to '.WalkInOrderService::PRICE_NOTE_MAX.' characters.',
            'method.*' => 'Choose how the customer paid: cash or GCash.',
            'gcash_reference.required_if' => 'Enter the GCash reference number from the customer’s receipt.',
            'gcash_reference.*' => 'The GCash reference must be 6 to 40 letters, digits or hyphens.',
            'cash_tendered.*' => 'Type the cash received as a number, or leave it empty.',
            'collected.accepted' => 'Tick the box to confirm you collected the full amount.',
            'files.*' => 'One of the files did not upload completely. Please choose it again.',
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $this->checkCustomer($validator);

            // Like the wizard: every option group the owner set up for this service needs one choice
            $service = PrintingService::query()->find((int) $this->input('service'));

            if ($service === null || $validator->errors()->hasAny(['service', 'options', 'options.*', 'options.*.*'])) {
                return;
            }

            $picked = $service->options()->active()->whereKey($this->optionIds())->get()->map(fn ($option) => $option->group->value)->all();

            foreach ($service->options()->active()->get()->map(fn ($option) => $option->group)->unique(fn ($group) => $group->value) as $group) {
                if (! in_array($group->value, $picked, true)) {
                    $validator->errors()->add("options.{$group->value}", 'Choose a '.strtolower($group->label()).'.');
                }
            }
        }];
    }

    /** @return array<int, int> */
    public function optionIds(): array
    {
        $chosen = (array) ($this->input('options.'.(int) $this->input('service')) ?? []);

        return array_values(array_map('intval', array_filter($chosen, fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false)));
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from((string) $this->validated('method'));
    }

    /** @return list<UploadedFile> */
    public function uploads(): array
    {
        return array_values(array_filter((array) $this->file('files', []), fn ($file) => $file instanceof UploadedFile));
    }
}
