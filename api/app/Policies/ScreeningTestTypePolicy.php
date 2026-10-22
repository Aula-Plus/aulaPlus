<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ScreeningTestType;
use App\Models\User;

/**
 * Screening tests are a school-wide, psychopedagogy-led module
 * (docs/prompts/11-pruebas-de-sondeo.md §4). A `teacher` never reaches the
 * catalog or the designs, so none of these methods ever grants a teacher.
 * (`teacher_visible` only decides whether a teacher may later read results
 * in the group profile and the student file — never this catalog.)
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

    /**
     * Psicopedagogía and Dirección edit a screening's general data (screen
     * 08): name, area, population, frequency and teacher access.
     */
    public function update(User $user, ScreeningTestType $type): bool
    {
        return $this->sharesSchool($user, $type)
            && $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    protected function sharesSchool(User $user, ScreeningTestType $type): bool
    {
        return $user->school_id === $type->school_id;
    }
}
