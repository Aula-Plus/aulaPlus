<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Student;
use App\Models\User;

/**
 * Reference policy for student records. Same two-layer pattern as GroupPolicy:
 * tenant isolation first, then role rules.
 *
 * A psychopedagogue and a director have automatic, school-wide access to every
 * student. A teacher only sees students that belong to a group they lead.
 */
class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Student $student): bool
    {
        if (! $this->sharesSchool($user, $student)) {
            return false;
        }

        if ($user->hasAnyRole(Role::schoolWideValues())) {
            return true;
        }

        // Teacher: the student must belong to a group this teacher leads.
        return $student->group !== null
            && $student->group->teacher_id === $user->id;
    }

    public function create(User $user): bool
    {
        // Director and psychopedagogue manage the roster; teachers do not.
        return $user->hasAnyRole(Role::schoolWideValues());
    }

    public function update(User $user, Student $student): bool
    {
        return $this->sharesSchool($user, $student)
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    public function delete(User $user, Student $student): bool
    {
        return $this->sharesSchool($user, $student)
            && $user->hasRole(Role::Director->value);
    }

    protected function sharesSchool(User $user, Student $student): bool
    {
        return $user->school_id === $student->school_id;
    }
}
