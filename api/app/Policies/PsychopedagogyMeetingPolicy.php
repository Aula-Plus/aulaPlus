<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\PsychopedagogyMeeting;
use App\Models\User;

/**
 * Screen 10 › Agenda (ClickUp 1dfd3mc4bge). The agenda is the team's, so
 * only psychopedagogy lists, schedules and completes its entries. Direction
 * reads the week's workload — how many of each type — never with whom
 * (P10-AGE-03). No teacher enters the space (the screen's red line).
 */
class PsychopedagogyMeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Psychopedagogue->value);
    }

    /** The header counts and «Carga de la semana». */
    public function viewWorkload(User $user): bool
    {
        return $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Psychopedagogue->value);
    }

    public function update(User $user, PsychopedagogyMeeting $meeting): bool
    {
        return $user->school_id === $meeting->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }
}
