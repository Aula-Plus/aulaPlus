<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\Student;
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
 * - create: any staff role. What direction or a teacher creates is a
 *   proposal psychopedagogy must confirm (AccommodationProposalWorkflow; a
 *   teacher only for a student whose file they open — StoreAccommodationRequest,
 *   ClickUp 1dfd3mc55zu). A teacher's proposal, until confirmed or rejected,
 *   is seen only by its proposer and psychopedagogy (P06-AJU-02).
 * - update: psychopedagogy, on any accommodation of its school (including an
 *   open proposal: it may edit before confirming). Direction only on a
 *   proposal still under review — it never edits or switches off a confirmed
 *   accommodation (screens 04/06: direction reads, psychopedagogy manages).
 *   A teacher never edits one: the response would carry the full record
 *   (origin, scope, description), including ones scoped to subjects the
 *   teacher can't see.
 * - confirm/return/reject a proposal: psychopedagogy only.
 * - resubmit/withdraw a proposal: direction for its proposals; a teacher's
 *   proposal, only that teacher (who also edits it while open).
 * - viewProposals (a student's proposals list) / viewReviewHistory:
 *   school-wide roles (direction never on a teacher's private proposal), and
 *   a teacher on their own proposals.
 * - viewInbox («Ajustes y PTP», screen 06, ClickUp 1dfd3mc4bgc): the
 *   school-wide list of every accommodation next to its student is
 *   psychopedagogy's alone (P06-DEC-07). A teacher never sees that roster
 *   (the screen's red line); direction reads accommodations in each file.
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
            && ($accommodation->isVisibleTo($user) || $this->isOwnTeacherProposal($user, $accommodation));
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

        if (! $accommodation->isOpenProposal()) {
            return false;
        }

        // A teacher's proposal: only that teacher (ClickUp 1dfd3mc55zu).
        if ($accommodation->isTeacherProposal()) {
            return $this->isOwnTeacherProposal($user, $accommodation);
        }

        return $this->isDirector($user);
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
        if (! $this->sharesSchool($user, $accommodation)) {
            return false;
        }

        if ($accommodation->isTeacherProposal()) {
            return $this->isOwnTeacherProposal($user, $accommodation);
        }

        return $this->isDirector($user);
    }

    public function withdraw(User $user, Accommodation $accommodation): bool
    {
        return $this->resubmit($user, $accommodation);
    }

    /**
     * A student's proposals list. A teacher gets only their own proposals of
     * a student whose file they can open (the controller filters the list).
     */
    public function viewProposals(User $user, ?Student $student = null): bool
    {
        if ($user->hasAnyRole(Role::schoolWideValues())) {
            return true;
        }

        return $student !== null
            && $user->hasRole(Role::Teacher->value)
            && $user->can('view', $student);
    }

    public function viewInbox(User $user): bool
    {
        return $this->isPsychopedagogue($user);
    }

    public function viewReviewHistory(User $user, Accommodation $accommodation): bool
    {
        if (! $this->sharesSchool($user, $accommodation)) {
            return false;
        }

        if ($this->isOwnTeacherProposal($user, $accommodation)) {
            return true;
        }

        if ($this->isPsychopedagogue($user)) {
            return true;
        }

        return $this->isDirector($user) && ! $accommodation->isPrivateTeacherProposal();
    }

    /**
     * The teacher who proposed it, while they can still open the student's
     * file (ClickUp 1dfd3mc55zu).
     */
    protected function isOwnTeacherProposal(User $user, Accommodation $accommodation): bool
    {
        if (! $accommodation->isTeacherProposal() || $accommodation->proposed_by_id !== $user->id) {
            return false;
        }

        $student = $accommodation->student;

        return $student !== null && $user->can('view', $student);
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
