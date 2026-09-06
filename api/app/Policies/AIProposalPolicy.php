<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\AIProposal;
use App\Models\Group;
use App\Models\User;

/**
 * Authorization for AI-assistant proposals (docs/prompts/
 * 05-asistente-ia-docente.md §3). Reuses the Sesión 2 building blocks rather
 * than defining any parallel rule:
 *
 * - generate: the same "can this user act on this group" check used elsewhere
 *   — a teacher who leads the group ({@see User::teachesGroup()}), or a
 *   school-wide role (director/psychopedagogue) in the same school.
 * - view: the requester, or a school-wide role in the same school (read-only).
 * - apply / discard: ONLY the requester. The spec states "solo el solicitante
 *   puede aplicar"; discard is gated identically by symmetry (a deliberate
 *   choice — a school-wide reader should not be able to throw away another
 *   teacher's draft either).
 */
class AIProposalPolicy
{
    /**
     * Whether $user may request a generation for $group. Called with the Group
     * as the second argument: no proposal exists yet at generation time.
     */
    public function generate(User $user, Group $group): bool
    {
        return $user->school_id === $group->school_id
            && ($user->teachesGroup($group) || $user->hasAnyRole(Role::schoolWideValues()));
    }

    public function view(User $user, AIProposal $proposal): bool
    {
        if ($user->school_id !== $proposal->school_id) {
            return false;
        }

        return $this->isRequester($user, $proposal)
            || $user->hasAnyRole(Role::schoolWideValues());
    }

    public function apply(User $user, AIProposal $proposal): bool
    {
        return $user->school_id === $proposal->school_id
            && $this->isRequester($user, $proposal);
    }

    public function discard(User $user, AIProposal $proposal): bool
    {
        return $this->apply($user, $proposal);
    }

    protected function isRequester(User $user, AIProposal $proposal): bool
    {
        return $proposal->requested_by_id === $user->id;
    }
}
