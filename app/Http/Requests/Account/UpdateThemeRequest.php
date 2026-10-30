<?php

namespace App\Http\Requests\Account;

use App\Enums\ColorScheme;
use Illuminate\Validation\Rule;

/** Light, Dark or System, from the account page form or the theme button in the header (sent as JSON). */
class UpdateThemeRequest extends AccountRequest
{
    protected $errorBag = 'theme';

    protected string $section = 'appearance';

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['theme' => ['required', 'string', Rule::enum(ColorScheme::class)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['theme.*' => 'Choose Light, Dark or System.'];
    }

    public function theme(): ColorScheme
    {
        return ColorScheme::from((string) $this->validated('theme'));
    }
}
