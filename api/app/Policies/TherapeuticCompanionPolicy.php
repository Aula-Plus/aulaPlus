<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Student;
use App\Models\TherapeuticCompanion;
use App\Models\User;

/**
 * Screen 10 › Acompañantes (ClickUp 1dfd3mc4bge). Psychopedagogy assigns,
 * edits and ends (an end date, never a delete) and reads the history;
 * direction reads the current list — who comes in, whom they accompany, which
 * days, how many hours and in which role. A teacher never reads the list
 * (P10-ACO-01): they see the current companions of a student they can view in
 * the file (StudentTrackingResource), role included (ClickUp decision: the
 * role is visible to whoever sees the student has a companion).
 * Tenant and role are both checked (defence in depth over SchoolScope).
 */
class TherapeuticCompanionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    /** Ended companions (the student's history) are psychopedagogy's alone. */
    public function viewHistory(User $user): bool
    {
        return $user->hasRole(Role::Psychopedagogue->value);
    }

    public function create(User $user, Student $student): bool
    {
        return $user->school_id === $student->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }

    public function update(User $user, TherapeuticCompanion $companion): bool
    {
        return $user->school_id === $companion->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }

    public function end(User $user, TherapeuticCompanion $companion): bool
    {
        return $this->update($user, $companion);
    }
}
