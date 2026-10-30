<?php

namespace App\Http\Requests\Owner\Reports;

use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The period of the reports: a quick choice, or a custom from / to on the shop's calendar days. Nothing chosen means
 * this month. Anything that does not pass sends the owner back to this month with a message.
 */
class ReportPeriodRequest extends FormRequest
{
    public const RANGES = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_7' => 'Last 7 days',
        'last_30' => 'Last 30 days',
        'all' => 'All time',
    ];

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.reports.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'range' => ['nullable', 'string', Rule::in(array_keys(self::RANGES))],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'range.*' => 'That period does not exist. This month is shown.',
            'from.*' => 'Pick a valid start date. This month is shown.',
            'to.after_or_equal' => 'The end date must be on or after the start date. This month is shown.',
            'to.*' => 'Pick a valid end date. This month is shown.',
        ];
    }

    /**
     * The chosen period: shop calendar days (Y-m-d) or null for an open end, the quick choice it matches ('custom' for
     * typed dates) and a label to show.
     *
     * @return array{range: string, from: ?string, to: ?string, label: string}
     */
    public function period(): array
    {
        $today = LocalTime::in(Carbon::now('UTC'));
        $from = $this->validated('from');
        $to = $this->validated('to');

        if ($from || $to) {
            return ['range' => 'custom', 'from' => $from, 'to' => $to, 'label' => self::label($from, $to)];
        }

        $range = $this->validated('range') ?? 'this_month';
        [$from, $to] = match ($range) {
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_7' => [$today->copy()->subDays(6), $today],
            'last_30' => [$today->copy()->subDays(29), $today],
            'all' => [null, null],
            default => [$today->copy()->startOfMonth(), $today],
        };

        return ['range' => $range, 'from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d'), 'label' => self::RANGES[$range]];
    }

    private static function label(?string $from, ?string $to): string
    {
        $day = fn (string $date) => Carbon::parse($date)->format('M j, Y');

        return match (true) {
            $from && $to => $day($from).' to '.$day($to),
            (bool) $from => 'From '.$day($from),
            default => 'Up to '.$day($to),
        };
    }
}
