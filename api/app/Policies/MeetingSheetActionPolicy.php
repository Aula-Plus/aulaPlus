<?php

namespace App\Policies;

use App\Models\MeetingSheetAction;
use App\Models\User;

/**
 * An action agreed in a meeting is a task of its owner (P10-DEC-08): only
 * they mark it done, and only once the sheet was confirmed (before that it
 * reached nobody).
 */
class MeetingSheetActionPolicy
{
    public function complete(User $user, MeetingSheetAction $action): bool
    {
        return $user->school_id === $action->school_id
            && $action->owner_id === $user->id
            && $action->sheet?->isConfirmed() === true;
    }
}
