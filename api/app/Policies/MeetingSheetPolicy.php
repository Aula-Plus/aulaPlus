<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MeetingSheet;
use App\Models\User;

/**
 * Screen 10 › Planillas (ClickUp 1dfd3mc4bge). The sheet is psychopedagogy's:
 * it creates, edits and confirms the split. Direction lists that each meeting
 * existed — date, type and group — never who was discussed, the text nor the
 * actions (P10-DEC-04; the listing endpoint serializes the reduced row). A
 * teacher never enters; what reaches them is the fragment in their student's
 * file and the actions they own.
 */
class MeetingSheetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    /** Who was there, who was discussed, the text, fragments and actions. */
    public function viewContent(User $user): bool
    {
        return $user->hasRole(Role::Psychopedagogue->value);
    }

    public function view(User $user, MeetingSheet $sheet): bool
    {
        return $user->school_id === $sheet->school_id && $this->viewContent($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Psychopedagogue->value);
    }

    public function update(User $user, MeetingSheet $sheet): bool
    {
        return $this->view($user, $sheet);
    }

    public function confirm(User $user, MeetingSheet $sheet): bool
    {
        return $this->view($user, $sheet);
    }
}
