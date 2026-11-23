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

    /**
     * A date as the SPA shows it (`formatShortDate` in web/src/lib/utils.ts),
     * e.g. «20 nov 2026», for text built server-side. Spelled out by hand so it
     * does not depend on the intl extension or the ICU version.
     */
    public static function shortDate(CarbonInterface $date): string
    {
        $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

        return $date->format('d').' '.$months[$date->month - 1].' '.$date->format('Y');
    }
}
