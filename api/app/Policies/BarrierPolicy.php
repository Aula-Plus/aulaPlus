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
 *
 * A barrier is part of one student's profile, so reaching it follows the
 * same rule as the student (StudentPolicy::view): school-wide roles see every
 * barrier of their school, a teacher only those of students they teach — the
 * same students whose barriers they already see as support chips in the
 * student profile. A teacher who doesn't teach the student can neither read
 * nor edit (propose accommodation links on) their barriers.
 */
class BarrierPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Barrier $barrier): bool
    {
        return $this->sharesSchool($user, $barrier)
            && ($user->hasAnyRole(Role::schoolWideValues()) || $this->teachesStudent($user, $barrier));
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([Role::Teacher->value, Role::Psychopedagogue->value]);
    }

    public function update(User $user, Barrier $barrier): bool
    {
        return $this->sharesSchool($user, $barrier)
            && ($user->hasRole(Role::Psychopedagogue->value)
                || ($user->hasRole(Role::Teacher->value) && $this->teachesStudent($user, $barrier)));
    }

    public function delete(User $user, Barrier $barrier): bool
    {
        return $this->update($user, $barrier);
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

    protected function teachesStudent(User $user, Barrier $barrier): bool
    {
        return $barrier->student !== null && $user->teachesStudent($barrier->student);
    }
}
