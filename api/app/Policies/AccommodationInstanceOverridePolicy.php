<?php

namespace App\Policies;

use App\Models\AccommodationInstanceOverride;
use App\Models\Assessment;
use App\Models\User;

/**
 * Authorization for per-instance accommodation deactivations
 * (docs/prompts/18-ajustes-categoria-instancia.md §2).
 *
 * - create: only the teacher who owns the target assessment. The spec asks to
 *   replicate AssessmentPolicy::isOwner's comparison inline
 *   (`$assessment->teacher_id === $user->id`) rather than call it — that method
 *   is `protected` on the other policy — plus the standard tenant check.
 * - view: same rule as AccommodationPolicy::view — shares the school (defence
 *   in depth over the SchoolScope; the assessment-scoped listing endpoint gates
 *   more narrowly on top of this, see AccommodationInstanceOverrideController).
 */
class AccommodationInstanceOverridePolicy
{
    public function view(User $user, AccommodationInstanceOverride $override): bool
    {
        return $user->school_id === $override->school_id;
    }

    public function create(User $user, Assessment $assessment): bool
    {
        return $user->school_id === $assessment->school_id
            && $assessment->teacher_id === $user->id;
    }
}
