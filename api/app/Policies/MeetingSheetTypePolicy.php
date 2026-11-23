<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MeetingSheetType;
use App\Models\User;

/**
 * The school's sheet types (P10-DEC-01): psychopedagogy uses them, direction
 * renames and adds them from «Colegio y estructura › Institución»
 * (P23-DEC-61). Nobody invents their own per sheet. No teacher reads them:
 * they never enter the space.
 */
class MeetingSheetTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, MeetingSheetType $type): bool
    {
        return $user->school_id === $type->school_id && $user->hasRole(Role::Director->value);
    }
}
