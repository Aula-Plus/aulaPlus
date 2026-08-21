<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Student;
use App\Models\User;

/**
 * Reference policy for student records. Same two-layer pattern as GroupPolicy:
 * tenant isolation first, then role rules.
 *
 * Director-only: creating and editing student records is restricted to
 * the director. Psychopedagogue keeps full read access (see view/viewAny)
 * but does not write.
 * A teacher only sees students enrolled (group_student) in a group they lead
 * (group_teacher).
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

        return $student->groups()
            ->whereHas('teachers', fn ($query) => $query->where('users.id', $user->id))
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, Student $student): bool
    {
        return $this->sharesSchool($user, $student)
            && $user->hasRole(Role::Director->value);
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
