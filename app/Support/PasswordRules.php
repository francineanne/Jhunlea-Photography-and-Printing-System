<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The one definition of the password rule. The server validation (Password::defaults) and the live hints on the
 * register and reset pages both read it, so what the pages show can never drift from what the server enforces.
 * If you change defaults(), change all() to match: each entry is one requirement of that rule.
 */
class PasswordRules
{
    public const MIN_LENGTH = 8;

    public static function defaults(): Password
    {
        return Password::min(self::MIN_LENGTH)->mixedCase()->numbers();
    }

    /** @return array<int, array{id: string, label: string, min?: int}> */
    public static function all(): array
    {
        return [
            ['id' => 'length', 'label' => 'At least '.self::MIN_LENGTH.' characters', 'min' => self::MIN_LENGTH],
            ['id' => 'lower', 'label' => 'One lowercase letter'],
            ['id' => 'upper', 'label' => 'One uppercase letter'],
            ['id' => 'number', 'label' => 'One number'],
        ];
    }

    /** Plain-text version of all(), for hints and for pages without JavaScript. */
    public static function summary(): string
    {
        return 'At least '.self::MIN_LENGTH.' characters, with an uppercase letter, a lowercase letter and a number.';
    }
}
