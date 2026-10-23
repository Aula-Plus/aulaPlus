<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Who may report creation-journey steps from the browser (ClickUp
 * 86e3qkxtc). Only teachers create content (programs, classes, assessments),
 * so only they have journeys; anyone else is refused rather than silently
 * accepted. Reading journeys is SchoolPolicy::viewAdoptionDashboard
 * (director of that school only).
 */
class UsageEventPolicy
{
    public function recordJourneyStep(User $user): bool
    {
        return $user->hasRole(Role::Teacher->value);
    }
}
