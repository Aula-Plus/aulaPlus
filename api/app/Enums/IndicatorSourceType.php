<?php

namespace App\Enums;

/**
 * The kinds of datum screen 30 works with (P30-DAT-02): a screening, the
 * year's grades in a subject, a datum loaded from outside, another indicator
 * and — only as a crossing condition — the grade in one assessment. Rubric
 * criteria are left out until per-criterion grading exists (screen 17, PO
 * decision on ClickUp 1dfd3mc4bgp).
 */
enum IndicatorSourceType: string
{
    case Screening = 'screening';
    case Subject = 'subject';
    case External = 'external';
    case Indicator = 'indicator';
    case Assessment = 'assessment';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The kinds an indicator can be built from (an assessment is a single
     * instance, so it only enters a crossing condition).
     *
     * @return list<string>
     */
    public static function indicatorInputValues(): array
    {
        return [self::Screening->value, self::Subject->value, self::External->value, self::Indicator->value];
    }
}
