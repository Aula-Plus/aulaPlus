<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Crossing;
use App\Models\User;

/**
 * A crossing (screen 30 «Cruces»): never opened to teachers (P30-DEC-03).
 *
 * Screen 30 «Indicadores y cruces» is only for Psicopedagogía and Dirección,
 * who both create and edit (P30-DAT/IND/CRU/GRA-01: a teacher does not enter).
 * No method ever grants a teacher; tenant isolation is checked here as well
 * as by the SchoolScope (defence in depth).
 */
class CrossingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaffOfScreen($user);
    }

    public function view(User $user, Crossing $crossing): bool
    {
        return $this->sharesSchool($user, $crossing) && $this->isStaffOfScreen($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffOfScreen($user);
    }

    public function update(User $user, Crossing $crossing): bool
    {
        return $this->sharesSchool($user, $crossing) && $this->isStaffOfScreen($user);
    }

    public function delete(User $user, Crossing $crossing): bool
    {
        return $this->sharesSchool($user, $crossing) && $this->isStaffOfScreen($user);
    }

    protected function isStaffOfScreen(User $user): bool
    {
        return $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    protected function sharesSchool(User $user, Crossing $crossing): bool
    {
        return $user->school_id === $crossing->school_id;
    }
}
