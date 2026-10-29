<?php

namespace App\Http\Requests\Notifications;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The All / Unread tab of a notifications page. Anything else sends the person back to All. */
class NotificationListRequest extends FormRequest
{
    public const TABS = ['all', 'unread'];

    public function authorize(): bool
    {
        return true; // the route middleware limits each page to its own area; the feed reads only this person's rows
    }

    protected function getRedirectUrl(): string
    {
        return route($this->user()?->role === UserRole::Owner ? 'owner.notifications.index' : 'customer.notifications.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['tab' => ['nullable', 'string', Rule::in(self::TABS)], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['tab.*' => 'That list does not exist.', 'page.*' => 'That page does not exist.'];
    }

    public function unreadOnly(): bool
    {
        return $this->validated('tab') === 'unread';
    }
}
