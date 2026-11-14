<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Group;
use App\Models\Subject;
use App\Models\User;

/**
 * Screen 23 › «Oferta curricular» and «Planificadores» are Administración:
 * only direction reads and configures them (P23-DEC-04). The resolved planner
 * of a group-subject is what screen 13 shows: the teacher who teaches that
 * subject in that group, and psychopedagogy and direction (read).
 *
 * Tenant isolation is asserted on every record (defence in depth over
 * SchoolScope).
 */
class CurricularOfferingPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, Group $group, Subject $subject): bool
    {
        return $this->manage($user)
            && $group->school_id === $user->school_id
            && $subject->school_id === $user->school_id;
    }

    public function viewPlanner(User $user, Group $group, Subject $subject): bool
    {
        if ($group->school_id !== $user->school_id || $subject->school_id !== $user->school_id) {
            return false;
        }

        if ($user->hasAnyRole(Role::schoolWideValues())) {
            return true;
        }

        return $group->teachers()
            ->where('teacher_id', $user->id)
            ->wherePivot('subject_id', $subject->id)
            ->exists();
    }
}
