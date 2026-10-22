<?php

namespace App\Enums;

/**
 * How much of a student's grades a teacher sees outside the subjects they
 * teach. Chosen school-wide by direction; psychopedagogy and direction always
 * see everything. The server enforces it (App\Services\StudentGradeAccess).
 */
enum GradesVisibility: string
{
    /** Only the subjects the teacher teaches (default). */
    case OwnSubject = 'own_subject';

    /** Every subject, live. */
    case AllLive = 'all_live';

    /** Every subject, as of the last periodic cutoff. */
    case AllPeriodic = 'all_periodic';
}
