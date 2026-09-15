<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Subject;
use App\Models\User;

/**
 * Subjects are a school catalog: any authenticated staff member reads them
 * (pickers and filters need the list), but only a director mutates them.
 * Tenant isolation is asserted first (defence-in-depth over SchoolScope).
 */
class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Subject $subject): bool
    {
        return $this->sharesSchool($user, $subject);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, Subject $subject): bool
    {
        return $this->sharesSchool($user, $subject)
            && $user->hasRole(Role::Director->value);
    }

    public function delete(User $user, Subject $subject): bool
    {
        return $this->sharesSchool($user, $subject)
            && $user->hasRole(Role::Director->value);
    }

    protected function sharesSchool(User $user, Subject $subject): bool
    {
        return $user->school_id === $subject->school_id;
    }
}
