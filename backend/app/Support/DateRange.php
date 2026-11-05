<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Turns inclusive calendar dates, read in a branch's timezone, into the
 * [start, end] instants to query with. Timestamps are stored in the app's
 * timezone (UTC by default), so "11 March in Dhaka" is converted rather than
 * compared as a bare date -- otherwise anything between midnight and 06:00 in
 * Dhaka would land on the wrong day.
 */
class DateRange
{
    /** The active branch's timezone, falling back to Dhaka. */
    public static function timezoneFor(Request $request): string
    {
        return $request->user()->activeBranch?->timezone ?? 'Asia/Dhaka';
    }

    /** @return array{0: Carbon, 1: Carbon} start-of-day and end-of-day, in the storage timezone */
    public static function bounds(string $fromDate, string $toDate, string $timezone): array
    {
        $storageTz = config('app.timezone', 'UTC');

        return [
            Carbon::parse($fromDate, $timezone)->startOfDay()->setTimezone($storageTz),
            Carbon::parse($toDate, $timezone)->endOfDay()->setTimezone($storageTz),
        ];
    }
}
