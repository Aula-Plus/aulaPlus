<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ScreeningTestType;
use App\Models\User;

/**
 * Screening tests are a school-wide, psychopedagogy-led module
 * (docs/prompts/11-pruebas-de-sondeo.md §4). A `teacher` sees and creates
 * NOTHING here — not even aggregates — by the same "línea roja" rule that
 * applies to PTP (screen 9). So none of these methods ever grants a teacher.
 */
class ScreeningTestTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }

    public function view(User $user, ScreeningTestType $type): bool
    {
        return $this->sharesSchool($user, $type)
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    /**
     * Only psychopedagogy creates a type (spec §1).
     */
    public function create(User $user): bool
    {
        return $user->hasRole(Role::Psychopedagogue->value);
    }

    protected function sharesSchool(User $user, ScreeningTestType $type): bool
    {
        return $user->school_id === $type->school_id;
    }
}
