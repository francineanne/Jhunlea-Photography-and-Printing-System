<?php

namespace App\Http\Requests\Owner;

use App\Enums\AuditAction;
use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The filters of the Activity log (Phase 8E): a group of actions, a period (Today, Yesterday, Last 7 days by default,
 * Custom, All time) and a search. Anything invalid sends the owner back to the default view with a message.
 */
class ActivityLogRequest extends FormRequest
{
    public const RANGES = ['today' => 'Today', 'yesterday' => 'Yesterday', 'last_7' => 'Last 7 days', 'all' => 'All time'];

    public function authorize(): bool
    {
        return true; // auth + role:owner on the route
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.activity.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'group' => ['nullable', 'string', Rule::in(array_keys(AuditAction::GROUPS))],
            'range' => ['nullable', 'string', Rule::in(array_keys(self::RANGES))],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
            'q' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'group.*' => 'That kind of activity does not exist. All activity of the last 7 days is shown.',
            'range.*' => 'That period does not exist. The last 7 days are shown.',
            'from.*' => 'Pick a valid start date. The last 7 days are shown.',
            'to.after_or_equal' => 'The end date must be on or after the start date. The last 7 days are shown.',
            'to.*' => 'Pick a valid end date. The last 7 days are shown.',
            'q.*' => 'Search with up to 60 characters. The last 7 days are shown.',
            'page.*' => 'That page does not exist. The last 7 days are shown.',
        ];
    }

    /** @return array{group: ?string, range: string, from: ?string, to: ?string, search: string} */
    public function filters(): array
    {
        $today = LocalTime::in(Carbon::now('UTC'));
        $from = $this->validated('from');
        $to = $this->validated('to');

        if ($from || $to) {
            $range = 'custom';
        } else {
            $range = $this->validated('range') ?? 'last_7';
            [$from, $to] = match ($range) {
                'today' => [$today->format('Y-m-d'), $today->format('Y-m-d')],
                'yesterday' => [$today->copy()->subDay()->format('Y-m-d'), $today->copy()->subDay()->format('Y-m-d')],
                'all' => [null, null],
                default => [$today->copy()->subDays(6)->format('Y-m-d'), $today->format('Y-m-d')],
            };
        }

        return ['group' => $this->validated('group'), 'range' => $range, 'from' => $from, 'to' => $to, 'search' => trim((string) $this->validated('q'))];
    }
}
