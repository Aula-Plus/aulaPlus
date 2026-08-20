<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\AnnualPlan;
use App\Models\User;

/**
 * An annual plan is owned by the teacher who created it (`teacher_id`).
 * Only that teacher may create/edit/delete it; director and psychopedagogue
 * get read-only, school-wide visibility (`view`), never write access.
 */
class AnnualPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AnnualPlan $annualPlan): bool
    {
        if (! $this->sharesSchool($user, $annualPlan)) {
            return false;
        }

        return $this->isOwner($user, $annualPlan)
            || $user->hasAnyRole(Role::schoolWideValues());
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Teacher->value);
    }

    public function update(User $user, AnnualPlan $annualPlan): bool
    {
        return $this->sharesSchool($user, $annualPlan) && $this->isOwner($user, $annualPlan);
    }

    public function delete(User $user, AnnualPlan $annualPlan): bool
    {
        return $this->sharesSchool($user, $annualPlan) && $this->isOwner($user, $annualPlan);
    }

    protected function isOwner(User $user, AnnualPlan $annualPlan): bool
    {
        return $annualPlan->teacher_id === $user->id;
    }

    protected function sharesSchool(User $user, AnnualPlan $annualPlan): bool
    {
        return $user->school_id === $annualPlan->school_id;
    }
}
