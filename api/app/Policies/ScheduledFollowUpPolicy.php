<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ScheduledFollowUp;
use App\Models\Student;
use App\Models\User;

/**
 * Authorization for scheduled follow-ups (docs/prompts/17-seguimiento-
 * programado.md §2). Deliberately a NEW policy, not a reuse of
 * AccommodationPolicy: unlike Accommodation::create (which only checks role),
 * this entity requires a real relationship with the student — a teacher may
 * only touch follow-ups on students they actually teach.
 *
 * The follow-up may reference sensitive observations about a minor, so the
 * rule is the same as viewing the student in tracking: same school AND
 * (teaches the student OR a school-wide clinical role). Tenant isolation and
 * the role rule are both checked here (defence in depth over SchoolScope, per
 * CLAUDE.md). `resolve` is intentionally the same rule as `create`: resolving
 * is not a four-eyes approval — anyone who could open one can close it.
 */
class ScheduledFollowUpPolicy
{
    public function view(User $user, ScheduledFollowUp $followUp): bool
    {
        return $this->canAccessStudent($user, $followUp->student);
    }

    /**
     * Authorization for GET /students/{student}/scheduled-follow-ups: there is
     * no single follow-up to check yet, so the same substantive rule is
     * applied at the student level (mirrors AlertPolicy::viewForGroup).
     */
    public function viewForStudent(User $user, Student $student): bool
    {
        return $this->canAccessStudent($user, $student);
    }

    public function create(User $user, Student $student): bool
    {
        return $this->canAccessStudent($user, $student);
    }

    public function resolve(User $user, ScheduledFollowUp $followUp): bool
    {
        return $this->canAccessStudent($user, $followUp->student);
    }

    /**
     * Same school AND (teaches the student OR a school-wide role). A teacher
     * only ever reaches their own students; a director/psychopedagogue reaches
     * any student in their own school.
     */
    protected function canAccessStudent(User $user, Student $student): bool
    {
        return $user->school_id === $student->school_id
            && ($user->teachesStudent($student) || $user->hasAnyRole(Role::schoolWideValues()));
    }
}
