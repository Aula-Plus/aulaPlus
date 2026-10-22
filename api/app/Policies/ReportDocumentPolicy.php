<?php

namespace App\Policies;

use App\Enums\ReportVisibility;
use App\Enums\Role;
use App\Models\ReportDocument;
use App\Models\Student;
use App\Models\User;

/**
 * Student reports — clinical data about a minor (CLAUDE.md rule 11), with
 * visibility per file (ClickUp 86e3jujgx):
 *
 * - psychopedagogy uploads, edits the anonymized text, confirms the AI send,
 *   reviews proposals, sets each file's visibility and deletes;
 * - direction opens clinical files (and sees the accommodations already
 *   approved, in «Ajustes») but never the anonymized text or the proposals;
 * - a teacher only opens the files psychopedagogy enabled for teachers, of
 *   students they teach. The extracted text and proposals are never theirs.
 */
class ReportDocumentPolicy
{
    public function viewAnyFor(User $user, Student $student): bool
    {
        return $user->school_id === $student->school_id
            && ($user->hasAnyRole(Role::schoolWideValues()) || $user->teachesStudent($student));
    }

    /** Whether the user may see this file exists and open it. */
    public function view(User $user, ReportDocument $report): bool
    {
        if ($user->school_id !== $report->school_id) {
            return false;
        }

        if ($user->hasAnyRole(Role::schoolWideValues())) {
            return true;
        }

        return $report->visibility === ReportVisibility::Teachers
            && $user->teachesStudent($report->student);
    }

    /**
     * Anonymized text, status details, proposals. Psychopedagogy only: a
     * proposal is an unreviewed AI draft and the document marks it
     * «[Solo Psicopedagogía]»; direction follows the student through the
     * accommodations psychopedagogy already approved.
     */
    public function viewContent(User $user, ReportDocument $report): bool
    {
        return $user->school_id === $report->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }

    public function create(User $user, Student $student): bool
    {
        return $user->school_id === $student->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }

    public function manage(User $user, ReportDocument $report): bool
    {
        return $user->school_id === $report->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }
}
