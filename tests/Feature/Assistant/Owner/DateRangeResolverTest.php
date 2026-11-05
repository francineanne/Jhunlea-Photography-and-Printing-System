<?php

namespace Tests\Feature\Assistant\Owner;

use App\Services\Assistant\Owner\AmbiguousDateRange;
use App\Services\Assistant\Owner\DateRangeResolver;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The owner tools' periods (Phase 7E-3a): presets and ISO dates in Manila days, weeks from Monday, no free text. */
class DateRangeResolverTest extends TestCase
{
    private function at(string $manila): DateRangeResolver
    {
        Carbon::setTestNow(Carbon::parse($manila, 'Asia/Manila')->utc());

        return app(DateRangeResolver::class);
    }

    private function days(DateRangeResolver $resolver, array $params): array
    {
        $range = $resolver->resolve($params);

        return [$range->from, $range->to];
    }

    public function test_every_preset_on_a_thursday(): void
    {
        $resolver = $this->at('2026-11-05 10:00');

        $this->assertSame(['2026-11-05', '2026-11-05'], $this->days($resolver, ['range' => 'today']));
        $this->assertSame(['2026-11-04', '2026-11-04'], $this->days($resolver, ['range' => 'yesterday']));
        $this->assertSame(['2026-11-02', '2026-11-05'], $this->days($resolver, ['range' => 'this_week']), 'The week starts on Monday');
        $this->assertSame(['2026-10-26', '2026-11-01'], $this->days($resolver, ['range' => 'last_week']));
        $this->assertSame(['2026-11-01', '2026-11-05'], $this->days($resolver, ['range' => 'this_month']));
        $this->assertSame(['2026-10-01', '2026-10-31'], $this->days($resolver, ['range' => 'last_month']));
        $this->assertSame(['2026-10-30', '2026-11-05'], $this->days($resolver, ['range' => 'last_7_days']));
        $this->assertSame(['2026-10-07', '2026-11-05'], $this->days($resolver, ['range' => 'last_30_days']));
        $this->assertSame([null, null], $this->days($resolver, ['range' => 'all_time']));
        $this->assertSame(['2026-11-05', '2026-11-05'], $this->days($resolver, []), 'The default is today');
    }

    public function test_the_manila_day_decides_not_the_utc_day(): void
    {
        // 11:30 PM Manila on Sunday Nov 8 is still Sunday; 12:30 AM on Monday Nov 9 is Monday (UTC is still Sunday)
        $this->assertSame(['2026-11-08', '2026-11-08'], $this->days($this->at('2026-11-08 23:30'), ['range' => 'today']));
        $this->assertSame(['2026-11-02', '2026-11-08'], $this->days($this->at('2026-11-08 23:30'), ['range' => 'this_week']));
        $this->assertSame(['2026-11-09', '2026-11-09'], $this->days($this->at('2026-11-09 00:30'), ['range' => 'today']));
        $this->assertSame(['2026-11-09', '2026-11-09'], $this->days($this->at('2026-11-09 00:30'), ['range' => 'this_week']));
        $this->assertSame(['2026-11-08', '2026-11-08'], $this->days($this->at('2026-11-09 00:30'), ['range' => 'yesterday']));
    }

    public function test_month_ends_and_explicit_dates(): void
    {
        $this->assertSame(['2027-02-01', '2027-02-28'], $this->days($this->at('2027-03-31 12:00'), ['range' => 'last_month']), 'March 31 has no February 31');
        $this->assertSame(['2027-01-01', '2027-01-31'], $this->days($this->at('2027-02-01 00:10'), ['range' => 'last_month']));

        $resolver = $this->at('2026-11-05 10:00');
        $this->assertSame(['2026-10-15', '2026-10-28'], $this->days($resolver, ['from' => '2026-10-15', 'to' => '2026-10-28']));
        $this->assertSame(['2026-11-05', '2026-11-05'], $this->days($resolver, ['from' => '2026-11-05', 'to' => '2026-11-05']), 'One day');
        $this->assertSame(14, $resolver->resolve(['from' => '2026-10-15', 'to' => '2026-10-28'])->days());
        $this->assertSame(['2026-10-01', '2026-10-14'], [$resolver->resolve(['from' => '2026-10-15', 'to' => '2026-10-28'])->previous()->from, $resolver->resolve(['from' => '2026-10-15', 'to' => '2026-10-28'])->previous()->to]);
    }

    public function test_impossible_ambiguous_or_too_long_ranges_ask_the_owner(): void
    {
        $resolver = $this->at('2026-11-05 10:00');

        foreach ([['from' => '2026-11-05'], ['to' => '2026-11-05'], ['from' => '2026-11-10', 'to' => '2026-11-01'], ['from' => '2026-02-30', 'to' => '2026-03-01'],
            ['from' => 'last tuesday', 'to' => '2026-11-05'], ['from' => '2025-01-01', 'to' => '2026-11-05'], ['range' => 'today', 'from' => '2026-11-01', 'to' => '2026-11-02'],
            ['range' => 'last_quarter']] as $params) {
            try {
                $resolver->resolve($params);
                $this->fail('Accepted '.json_encode($params));
            } catch (AmbiguousDateRange $question) {
                $this->assertStringEndsWith('.', rtrim($question->getMessage(), '?').'.');
            }
        }

        $this->assertSame(366, $resolver->resolve(['from' => '2025-11-05', 'to' => '2026-11-05'])->days(), 'A year and a day is the most');
    }
}
