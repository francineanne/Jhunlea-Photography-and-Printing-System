<?php

namespace App\Http\Requests\Notifications;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The All / Unread (/ System, owner only, Phase 10K) tab of a notifications page. Anything else sends the person back to All. */
class NotificationListRequest extends FormRequest
{
    public const TABS = ['all', 'unread'];

    /** Phase 10K: what the system did or noticed by itself; the owner's page only. */
    public const OWNER_TABS = ['all', 'unread', 'system'];

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
        $tabs = $this->user()?->role === UserRole::Owner ? self::OWNER_TABS : self::TABS;

        return ['tab' => ['nullable', 'string', Rule::in($tabs)], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']];
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

    public function systemOnly(): bool
    {
        return $this->validated('tab') === 'system';
    }
}
