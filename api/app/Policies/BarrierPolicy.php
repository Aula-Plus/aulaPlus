<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Barrier;
use App\Models\User;

/**
 * Holds sensitive data about a minor's learning profile — see CLAUDE.md
 * security rule 11. Unlike Accommodation, `director` is deliberately NOT
 * included for create/update/delete (doc02 §2 grants this only to teacher
 * and psychopedagogue — director is not listed).
 */
class BarrierPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Barrier $barrier): bool
    {
        return $this->sharesSchool($user, $barrier);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([Role::Teacher->value, Role::Psychopedagogue->value]);
    }

    public function update(User $user, Barrier $barrier): bool
    {
        return $this->sharesSchool($user, $barrier)
            && $user->hasAnyRole([Role::Teacher->value, Role::Psychopedagogue->value]);
    }

    public function delete(User $user, Barrier $barrier): bool
    {
        return $this->sharesSchool($user, $barrier)
            && $user->hasAnyRole([Role::Teacher->value, Role::Psychopedagogue->value]);
    }

    protected function sharesSchool(User $user, Barrier $barrier): bool
    {
        return $user->school_id === $barrier->school_id;
    }
}
