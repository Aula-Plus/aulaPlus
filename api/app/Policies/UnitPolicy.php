<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Unit;
use App\Models\User;

/**
 * A Unit has no school_id of its own (see App\Models\Unit) and no owner
 * column — both tenant and ownership are inherited through its AnnualPlan.
 * Same access rule as AnnualPlan: only the plan's owning teacher writes,
 * director/psychopedagogue get read-only, school-wide visibility.
 */
class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Unit $unit): bool
    {
        if (! $this->sharesSchool($user, $unit)) {
            return false;
        }

        return $this->isOwner($user, $unit)
            || $user->hasAnyRole(Role::schoolWideValues());
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Teacher->value);
    }

    public function update(User $user, Unit $unit): bool
    {
        return $this->sharesSchool($user, $unit) && $this->isOwner($user, $unit);
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $this->sharesSchool($user, $unit) && $this->isOwner($user, $unit);
    }

    protected function isOwner(User $user, Unit $unit): bool
    {
        return $unit->loadMissing('annualPlan')->annualPlan->teacher_id === $user->id;
    }

    protected function sharesSchool(User $user, Unit $unit): bool
    {
        return $user->school_id === $unit->loadMissing('annualPlan')->annualPlan->school_id;
    }
}
