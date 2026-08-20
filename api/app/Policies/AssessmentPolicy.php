<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\User;

/**
 * An assessment is owned by the teacher who created it (`teacher_id`). Same
 * rule as AnnualPlan: only the owning teacher writes, director/psychopedagogue
 * get read-only, school-wide visibility.
 */
class AssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Assessment $assessment): bool
    {
        if (! $this->sharesSchool($user, $assessment)) {
            return false;
        }

        return $this->isOwner($user, $assessment)
            || $user->hasAnyRole(Role::schoolWideValues());
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Teacher->value);
    }

    public function update(User $user, Assessment $assessment): bool
    {
        return $this->sharesSchool($user, $assessment) && $this->isOwner($user, $assessment);
    }

    public function delete(User $user, Assessment $assessment): bool
    {
        return $this->sharesSchool($user, $assessment) && $this->isOwner($user, $assessment);
    }

    protected function isOwner(User $user, Assessment $assessment): bool
    {
        return $assessment->teacher_id === $user->id;
    }

    protected function sharesSchool(User $user, Assessment $assessment): bool
    {
        return $user->school_id === $assessment->school_id;
    }
}
