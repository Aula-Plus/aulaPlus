<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ExternalMeasure;
use App\Models\User;

/**
 * A datum loaded from outside (screen 30 «Datos»): Psicopedagogía and Dirección see and load data.
 *
 * Screen 30 «Indicadores y cruces» is only for Psicopedagogía and Dirección,
 * who both create and edit (P30-DAT/IND/CRU/GRA-01: a teacher does not enter).
 * No method ever grants a teacher; tenant isolation is checked here as well
 * as by the SchoolScope (defence in depth).
 */
class ExternalMeasurePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaffOfScreen($user);
    }

    public function view(User $user, ExternalMeasure $externalMeasure): bool
    {
        return $this->sharesSchool($user, $externalMeasure) && $this->isStaffOfScreen($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffOfScreen($user);
    }

    public function update(User $user, ExternalMeasure $externalMeasure): bool
    {
        return $this->sharesSchool($user, $externalMeasure) && $this->isStaffOfScreen($user);
    }

    public function delete(User $user, ExternalMeasure $externalMeasure): bool
    {
        return $this->sharesSchool($user, $externalMeasure) && $this->isStaffOfScreen($user);
    }

    protected function isStaffOfScreen(User $user): bool
    {
        return $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    protected function sharesSchool(User $user, ExternalMeasure $externalMeasure): bool
    {
        return $user->school_id === $externalMeasure->school_id;
    }
}
