<?php

namespace App\Http\Requests\Chat;

use App\Enums\OwnerPresenceMode;
use Illuminate\Validation\Rule;

/** The owner's presence mode: available, away or auto. */
class PresenceRequest extends ChatRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['mode' => ['required', Rule::enum(OwnerPresenceMode::class)]];
    }

    public function mode(): OwnerPresenceMode
    {
        return OwnerPresenceMode::from((string) $this->input('mode'));
    }
}
