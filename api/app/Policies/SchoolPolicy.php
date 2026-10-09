<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\School;
use App\Models\User;

/**
 * School-level authorization decisions, always tied to that specific school
 * (never "any director"): the adoption dashboard (docs/prompts/04-seguimiento-
 * institucional.md §5) and the comment trend threshold settings.
 */
class SchoolPolicy
{
    public function viewAdoptionDashboard(User $user, School $school): bool
    {
        return $user->school_id === $school->id
            && $user->hasRole(Role::Director->value);
    }

    /**
     * The comment trend threshold: psychopedagogy and direction read it (they
     * are the ones who see the trend mark).
     */
    public function viewCommentTrendSettings(User $user, School $school): bool
    {
        return $user->school_id === $school->id
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    /**
     * Only direction changes the comment trend threshold.
     */
    public function updateCommentTrendSettings(User $user, School $school): bool
    {
        return $user->school_id === $school->id
            && $user->hasRole(Role::Director->value);
    }
}
