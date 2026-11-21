<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Student;
use App\Models\TherapeuticCompanion;
use App\Models\User;

/**
 * Screen 10 › Acompañantes (ClickUp 1dfd3mc4bge). Psychopedagogy assigns and
 * edits; direction reads the full list — who comes in, whom they accompany,
 * which days and how many hours — without the reason (P10-DEC-09). A teacher
 * never reads the list (P10-ACO-01): they see the companion of their own
 * student in the file (StudentTrackingResource), also without the reason.
 * Tenant and role are both checked (defence in depth over SchoolScope).
 */
class TherapeuticCompanionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    /** The «motivo» is psychopedagogy's alone. */
    public function viewReason(User $user): bool
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

    public function delete(User $user, TherapeuticCompanion $companion): bool
    {
        return $this->update($user, $companion);
    }
}
