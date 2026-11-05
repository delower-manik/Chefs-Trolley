<?php

namespace Tests\Unit;

use App\Support\DateRange;
use Carbon\Carbon;
use Tests\TestCase;

class DateRangeTest extends TestCase
{
    public function test_a_dhaka_calendar_day_starts_at_18_00_utc_the_previous_day(): void
    {
        [$start, $end] = DateRange::bounds('2026-03-11', '2026-03-11', 'Asia/Dhaka');

        $this->assertSame('2026-03-10 18:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-11 17:59:59', $end->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $start->getTimezone()->getName());
    }

    public function test_an_order_at_02_00_dhaka_belongs_to_that_dhaka_day(): void
    {
        // 02:00 on the 11th in Dhaka is 20:00 on the 10th in UTC.
        $placed = Carbon::parse('2026-03-10 20:00:00', 'UTC');
        [$start, $end] = DateRange::bounds('2026-03-11', '2026-03-11', 'Asia/Dhaka');

        $this->assertTrue($placed->betweenIncluded($start, $end));
    }

    public function test_a_multi_day_range_spans_from_first_start_to_last_end(): void
    {
        [$start, $end] = DateRange::bounds('2026-03-01', '2026-03-31', 'Asia/Dhaka');

        $this->assertSame('2026-02-28 18:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 17:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_a_timezone_behind_utc_shifts_the_other_way(): void
    {
        [$start] = DateRange::bounds('2026-03-11', '2026-03-11', 'America/New_York');

        // New York is UTC-4 on 11 March 2026 (daylight time started on the 8th).
        $this->assertSame('2026-03-11 04:00:00', $start->format('Y-m-d H:i:s'));
    }
}
