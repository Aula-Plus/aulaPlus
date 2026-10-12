<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The schools' local calendar (config `app.school_timezone`). Timestamps are
 * stored in the app timezone (UTC); rules defined by a local calendar day — "the
 * last cutoff was on July 1st" — use this so the day doesn't start 3 hours late
 * (Uruguay is UTC-3).
 */
class SchoolTime
{
    public static function timezone(): string
    {
        return (string) config('app.school_timezone', 'America/Montevideo');
    }

    /**
     * Today's date in the school's timezone, at local midnight.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(static::timezone())->startOfDay();
    }

    /**
     * The instant a local calendar day starts, expressed in the app timezone
     * (UTC) so it can be compared with stored timestamps.
     */
    public static function startOfDay(CarbonInterface|string $date): CarbonImmutable
    {
        $day = $date instanceof CarbonInterface ? $date->format('Y-m-d') : $date;

        return CarbonImmutable::parse($day, static::timezone())
            ->startOfDay()
            ->setTimezone((string) config('app.timezone'));
    }
}
