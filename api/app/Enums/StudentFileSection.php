<?php

namespace App\Enums;

use App\Support\StudentFileHistory;

/**
 * The section of a student's file ("ficha del alumno") an `audit_logs` entry
 * belongs to — what the change history (ClickUp 86e3qkwmc) is filtered by.
 * Resolved at write time by {@see StudentFileHistory}; Spanish labels live in
 * the frontend (`studentFileSectionLabels` in web/src/types.ts).
 */
enum StudentFileSection: string
{
    /** Basic record: name, photo, birth date, enrollment year, AT flag, group. */
    case StudentData = 'student_data';

    /** Team-facing summary of the technical report (psychopedagogy writes it). */
    case Summary = 'summary';

    /** learning_profile / individual_profile / tracking_notes. */
    case LearningProfile = 'learning_profile';

    /** related_documents on the student record. */
    case Documents = 'documents';

    /** Accommodations ("ajustes"), incl. per-assessment deactivations. */
    case Accommodations = 'accommodations';

    /** Barriers, incl. barrier↔accommodation links and their validation. */
    case Barriers = 'barriers';

    /** Comments on the student ("observaciones"). */
    case Observations = 'observations';

    /** Technical/psychopedagogical reports ("informes"). */
    case Reports = 'reports';

    /** Scheduled follow-ups shown on the file's summary tab. */
    case FollowUps = 'follow_ups';

    /** Alerts on the student (outcome, resolution). */
    case Alerts = 'alerts';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $section): string => $section->value, self::cases());
    }
}
