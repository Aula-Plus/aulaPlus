<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\AIInputMark;
use App\Models\Group;
use App\Models\User;

/**
 * «Lo que recibe la IA» review view and its marks (ClickUp 1dfd3mc2wx2;
 * documento vivo screen 03, P03-AJU-01 / P03-AJU-03 / P03-ACT-53):
 *
 * - viewing it: psychopedagogy and direction (direction only reads). A
 *   teacher never opens it — P03-AJU-03 is «[Psicopedagogía y Dirección]».
 * - marking a line «La IA lo entendió mal» and «El ajuste está bien»:
 *   psychopedagogy only.
 *
 * Tenant isolation is checked as well, on top of the SchoolScope.
 */
class AIInputMarkPolicy
{
    public function viewAny(User $user, Group $group): bool
    {
        return $user->school_id === $group->school_id
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    public function create(User $user, Group $group): bool
    {
        return $user->school_id === $group->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }

    /** «El ajuste está bien» on a mark (any psychopedagogue of the school). */
    public function confirmAdjustment(User $user, AIInputMark $mark): bool
    {
        return $user->school_id === $mark->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }
}
