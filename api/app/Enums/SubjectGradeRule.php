<?php

namespace App\Enums;

/**
 * Which of the year's grades in a subject a datum of screen 30 reads (PO
 * answer of 17/11 on ClickUp 1dfd3mc4bgp: «siempre se tiene que ser lo más
 * certero posible al decir qué datos va a cruzar»). Whoever builds the
 * indicator or the crossing chooses it. The category is the assessment type
 * (AssessmentType: written, assignment, project, oral, submission).
 *
 * - `all`: the average of every grade of the year; the level of progress is
 *   the latest of the year. The behaviour before the rule existed, and the
 *   default.
 * - `all_except_category`: the same, leaving one category out.
 * - `category_average`: the same, only within one category.
 * - `latest`: only the latest grade of the year (Primaria: the latest level).
 * - `latest_of_category`: only the latest grade of one category.
 */
enum SubjectGradeRule: string
{
    case All = 'all';
    case AllExceptCategory = 'all_except_category';
    case CategoryAverage = 'category_average';
    case Latest = 'latest';
    case LatestOfCategory = 'latest_of_category';

    public function needsCategory(): bool
    {
        return in_array($this, [self::AllExceptCategory, self::CategoryAverage, self::LatestOfCategory], true);
    }

    /** Only the latest result counts (otherwise every result is averaged). */
    public function isLatest(): bool
    {
        return $this === self::Latest || $this === self::LatestOfCategory;
    }
}
