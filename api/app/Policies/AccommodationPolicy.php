<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\User;

/**
 * Holds sensitive data about a minor's learning profile — see CLAUDE.md
 * security rule 11.
 *
 * - view: tenant check, then the visibility rule of
 *   Accommodation::scopeVisibleTo — school-wide roles see every accommodation;
 *   a teacher only the general ones and those scoped to a subject they teach
 *   the student (ClickUp 86e3dpbwy follow-up), never an unconfirmed direction
 *   proposal (ClickUp 86e3qkwkk).
 * - create: any staff role — a teacher may propose one (living document,
 *   screen 06). What direction creates is a proposal psychopedagogy must
 *   confirm (AccommodationProposalWorkflow).
 * - update: psychopedagogy, on any accommodation of its school (including an
 *   open proposal: it may edit before confirming). Direction only on a
 *   proposal still under review — it never edits or switches off a confirmed
 *   accommodation (screens 04/06: direction reads, psychopedagogy manages).
 *   A teacher never edits one: the response would carry the full record
 *   (origin, scope, description), including ones scoped to subjects the
 *   teacher can't see.
 * - confirm/return/reject a proposal: psychopedagogy only.
 * - resubmit/withdraw a proposal: direction only.
 * - viewProposals (a student's proposals list) / viewReviewHistory:
 *   school-wide roles.
 *
 * Whether a proposal is in a state that allows a step is a business-state
 * precondition checked by the workflow (422), not here (403).
 */
class AccommodationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Accommodation $accommodation): bool
    {
        return $this->sharesSchool($user, $accommodation)
            && $accommodation->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(Role::values());
    }

    public function update(User $user, Accommodation $accommodation): bool
    {
        if (! $this->sharesSchool($user, $accommodation)) {
            return false;
        }

        if ($this->isPsychopedagogue($user)) {
            return true;
        }

        return $this->isDirector($user) && $accommodation->isOpenProposal();
    }

    public function delete(User $user, Accommodation $accommodation): bool
    {
        return $this->sharesSchool($user, $accommodation) && $this->isPsychopedagogue($user);
    }

    public function confirm(User $user, Accommodation $accommodation): bool
    {
        return $this->sharesSchool($user, $accommodation) && $this->isPsychopedagogue($user);
    }

    public function returnProposal(User $user, Accommodation $accommodation): bool
    {
        return $this->confirm($user, $accommodation);
    }

    public function reject(User $user, Accommodation $accommodation): bool
    {
        return $this->confirm($user, $accommodation);
    }

    public function resubmit(User $user, Accommodation $accommodation): bool
    {
        return $this->sharesSchool($user, $accommodation) && $this->isDirector($user);
    }

    public function withdraw(User $user, Accommodation $accommodation): bool
    {
        return $this->resubmit($user, $accommodation);
    }

    public function viewProposals(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }

    public function viewReviewHistory(User $user, Accommodation $accommodation): bool
    {
        return $this->sharesSchool($user, $accommodation)
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    protected function sharesSchool(User $user, Accommodation $accommodation): bool
    {
        return $user->school_id === $accommodation->school_id;
    }

    protected function isPsychopedagogue(User $user): bool
    {
        return $user->hasRole(Role::Psychopedagogue->value);
    }

    protected function isDirector(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }
}
