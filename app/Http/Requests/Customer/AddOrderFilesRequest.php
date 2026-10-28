<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\ValidatesOrderFiles;
use App\Models\PrintingOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/** More files for an order that is still Submitted or Under review, with the same rules as the wizard. */
class AddOrderFilesRequest extends FormRequest
{
    use ValidatesOrderFiles;

    public function authorize(): bool
    {
        return Gate::allows('addFiles', $this->order());
    }

    /** A failed upload returns to the files section, where the error is shown. */
    protected function getRedirectUrl(): string
    {
        return route('customer.printing.show', $this->order()).'#files';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->fileRules(config('printing.max_files_per_order') - $this->order()->files()->count());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $room = config('printing.max_files_per_order') - $this->order()->files()->count();

        return ['files.max' => $room > 0 ? "This order can take {$room} more ".($room === 1 ? 'file' : 'files').'.' : 'This order already has the most files it can hold.'] + $this->fileMessages();
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkFiles($validator, $this->order()->service, (int) $this->order()->files()->sum('size_bytes'))];
    }

    public function order(): PrintingOrder
    {
        return $this->route('order');
    }
}
