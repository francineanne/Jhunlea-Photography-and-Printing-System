<?php

namespace Tests\Feature;

use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LocalTimeTest extends TestCase
{
    public function test_a_utc_time_is_shown_in_manila_time(): void
    {
        $this->assertSame('Asia/Manila', config('app.display_timezone'));
        $this->assertSame('Oct 28, 2026 10:08 AM', LocalTime::format(Carbon::parse('2026-10-28 02:08:00', 'UTC')));
    }

    public function test_an_evening_utc_time_is_the_next_day_in_manila(): void
    {
        // 16:30 UTC is 00:30 the next day in Manila (UTC+8)
        $this->assertSame('Oct 29, 2026 12:30 AM', LocalTime::format(Carbon::parse('2026-10-28 16:30:00', 'UTC')));
        $this->assertSame('Jan 1, 2027 12:00 AM', LocalTime::format(Carbon::parse('2026-12-31 16:00:00', 'UTC')));
        // One second earlier is still the old day and the old year
        $this->assertSame('Dec 31, 2026 11:59 PM', LocalTime::format(Carbon::parse('2026-12-31 15:59:59', 'UTC')));
    }

    public function test_a_stored_string_without_a_zone_is_read_as_utc(): void
    {
        $this->assertSame('Oct 29, 2026', LocalTime::format('2026-10-28 16:30:00', LocalTime::DATE));
    }

    public function test_a_carbon_in_another_zone_keeps_its_moment(): void
    {
        $this->assertSame('Oct 29, 2026 12:30 AM', LocalTime::format(Carbon::parse('2026-10-28 12:30:00', 'America/New_York')));
    }

    public function test_an_empty_date_shows_a_dash(): void
    {
        $this->assertSame('—', LocalTime::format(null));
    }

    public function test_a_manila_day_covers_the_right_utc_hours(): void
    {
        $this->assertSame('2026-10-27 16:00:00', LocalTime::dayStartUtc('2026-10-28')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28 15:59:59', LocalTime::dayEndUtc('2026-10-28')->format('Y-m-d H:i:s'));
    }

    public function test_the_component_shows_local_time_and_keeps_utc_for_machines(): void
    {
        $html = Blade::render('<x-ui.date :value="$at" />', ['at' => Carbon::parse('2026-10-28 16:30:00', 'UTC')]);

        $this->assertStringContainsString('datetime="2026-10-28T16:30:00Z"', $html);
        $this->assertStringContainsString('Oct 29, 2026 12:30 AM', $html);
    }

    public function test_storage_stays_in_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }
}
