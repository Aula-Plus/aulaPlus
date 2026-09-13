<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\User;

/**
 * Authorization for assessment results (docs/prompts/13-evaluaciones-
 * resultados.md §2). A result has no independent owner — it inherits the
 * authorization of its parent {@see Assessment}, so every method here takes the
 * parent assessment as its subject rather than the result row:
 *
 * - view: school-wide, same rule as {@see AssessmentPolicy::view()} (a result
 *   is an academic score, not clinical data — the owning teacher plus the
 *   school-wide roles, all within the same school).
 * - create / update: only the teacher who owns the parent assessment, same
 *   rule as {@see AssessmentPolicy::update()}.
 *
 * Deliberately mirrors AssessmentPolicy's checks rather than rewriting the
 * Session 2 policy — the two must stay in lockstep by design.
 */
class AssessmentResultPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Assessment $assessment): bool
    {
        if (! $this->sharesSchool($user, $assessment)) {
            return false;
        }

        return $this->isOwner($user, $assessment)
            || $user->hasAnyRole(Role::schoolWideValues());
    }

    public function create(User $user, Assessment $assessment): bool
    {
        return $this->sharesSchool($user, $assessment) && $this->isOwner($user, $assessment);
    }

    public function update(User $user, Assessment $assessment): bool
    {
        return $this->create($user, $assessment);
    }

    protected function isOwner(User $user, Assessment $assessment): bool
    {
        return $assessment->teacher_id === $user->id;
    }

    protected function sharesSchool(User $user, Assessment $assessment): bool
    {
        return $user->school_id === $assessment->school_id;
    }
}
