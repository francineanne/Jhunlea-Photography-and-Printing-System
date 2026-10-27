<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('jhunlea:create-owner')]
#[Description('Create the shop owner account (asks for name, email and password)')]
class CreateOwner extends Command
{
    public function handle(): int
    {
        if (User::where('role', UserRole::Owner)->exists()) {
            $this->warn('An owner account already exists.');

            if (! confirm('Do you really want to create another owner account?', default: false)) {
                $this->error('Cancelled. No account was created.');

                return self::FAILURE;
            }
        }

        $name = text('Name', validate: fn (string $value) => $this->firstError('name', $value, ['required', 'string', 'max:255']));

        $email = strtolower(text('Email', validate: fn (string $value) => $this->firstError(
            'email', strtolower(trim($value)), ['required', 'string', 'email', 'max:255', 'unique:users,email']
        )));

        // The password is read as a hidden prompt; it is never a command-line argument, so it stays out of shell history
        $password = password('Password', validate: fn (string $value) => $this->firstError('password', $value, ['required', Password::defaults()]));

        password('Confirm password', validate: fn (string $value) => $value === $password ? null : 'The passwords do not match.');

        $owner = new User(['name' => trim($name), 'email' => trim($email), 'password' => $password]);
        $owner->role = UserRole::Owner;

        try {
            $owner->save();
        } catch (UniqueConstraintViolationException) {
            // The email was taken between the check above and the save
            $this->error('That email address was just taken by another account. No account was created.');

            return self::FAILURE;
        }

        $this->info("Owner account created for {$owner->email}.");

        return self::SUCCESS;
    }

    /** @param array<int, mixed> $rules */
    private function firstError(string $field, string $value, array $rules): ?string
    {
        return Validator::make([$field => $value], [$field => $rules])->errors()->first($field) ?: null;
    }
}
