<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ScreeningTestResult;
use App\Models\User;

/**
 * Loading a score onto a result (docs/prompts/11-pruebas-de-sondeo.md §§3-4):
 * psychopedagogy only, same school. Never a teacher, never a director.
 */
class ScreeningTestResultPolicy
{
    public function update(User $user, ScreeningTestResult $result): bool
    {
        return $user->school_id === $result->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }
}
