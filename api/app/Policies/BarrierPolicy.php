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

    /**
     * Authorization for validating a barrier↔accommodation link
     * (docs/prompts/03-flujos-aprobacion-trazabilidad.md §4): role/tenant
     * only. The four-eyes rule (validator ≠ proposer) is a business-state
     * precondition checked separately by the controller, which responds 422
     * (not 403) when it fails — same split as
     * AccommodationPolicy::approve()/reject().
     */
    public function validate(User $user, Barrier $barrier): bool
    {
        return $this->sharesSchool($user, $barrier)
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    protected function sharesSchool(User $user, Barrier $barrier): bool
    {
        return $user->school_id === $barrier->school_id;
    }
}
