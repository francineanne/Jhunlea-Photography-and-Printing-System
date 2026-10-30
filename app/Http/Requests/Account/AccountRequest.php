<?php

namespace App\Http\Requests\Account;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the three account forms (profile, password, appearance) on /my/account and /owner/account. Each form has
 * its own error bag, so a failed section shows its errors in that section only and never touches the others.
 */
abstract class AccountRequest extends FormRequest
{
    /** The section of the page the form returns to. */
    protected string $section = '';

    public function authorize(): bool
    {
        return true; // auth + role middleware on the route; every form changes only the signed-in person's own account
    }

    protected function getRedirectUrl(): string
    {
        return self::pageUrl($this->user()->role).'#'.$this->section;
    }

    public static function pageUrl(UserRole $role): string
    {
        return route($role === UserRole::Owner ? 'owner.account.show' : 'customer.account.show');
    }
}
