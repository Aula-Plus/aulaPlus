<?php

namespace App\Enums;

/**
 * Against whom a Z score is computed (PO decision on ClickUp 1dfd3mc4bgp):
 * the students of the same grade and school year (default), the student's
 * own group, or the whole school. The population used is shown next to the
 * score.
 */
enum ZScorePopulation: string
{
    case GradeYear = 'grade_year';
    case Group = 'group';
    case School = 'school';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
