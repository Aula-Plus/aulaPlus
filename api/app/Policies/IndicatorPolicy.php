<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Indicator;
use App\Models\User;

/**
 * An indicator (screen 30 «Indicadores»).
 *
 * Screen 30 «Indicadores y cruces» is only for Psicopedagogía and Dirección,
 * who both create and edit (P30-DAT/IND/CRU/GRA-01: a teacher does not enter).
 * No method ever grants a teacher; tenant isolation is checked here as well
 * as by the SchoolScope (defence in depth).
 */
class IndicatorPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaffOfScreen($user);
    }

    public function view(User $user, Indicator $indicator): bool
    {
        return $this->sharesSchool($user, $indicator) && $this->isStaffOfScreen($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffOfScreen($user);
    }

    public function update(User $user, Indicator $indicator): bool
    {
        return $this->sharesSchool($user, $indicator) && $this->isStaffOfScreen($user);
    }

    public function delete(User $user, Indicator $indicator): bool
    {
        return $this->sharesSchool($user, $indicator) && $this->isStaffOfScreen($user);
    }

    protected function isStaffOfScreen(User $user): bool
    {
        return $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    protected function sharesSchool(User $user, Indicator $indicator): bool
    {
        return $user->school_id === $indicator->school_id;
    }
}
