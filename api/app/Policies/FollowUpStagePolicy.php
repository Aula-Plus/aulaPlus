<?php

namespace App\Policies;

use App\Models\FollowUpStage;
use App\Models\User;

/**
 * Abilities on a single follow-up stage, auto-discovered for FollowUpStage.
 * The rules themselves live in ScheduledFollowUpPolicy, next to the rest of
 * the follow-up's permission matrix, so there is one place to read them.
 */
class FollowUpStagePolicy
{
    public function __construct(protected ScheduledFollowUpPolicy $followUps) {}

    public function update(User $user, FollowUpStage $stage): bool
    {
        return $this->followUps->updateStage($user, $stage);
    }

    public function split(User $user, FollowUpStage $stage): bool
    {
        return $this->followUps->splitStage($user, $stage);
    }

    public function close(User $user, FollowUpStage $stage): bool
    {
        return $this->followUps->closeStage($user, $stage);
    }

    public function addNote(User $user, FollowUpStage $stage): bool
    {
        return $this->followUps->addStageNote($user, $stage);
    }
}
