<?php

namespace App\Notifications;

use App\Models\FollowUpStage;
use App\Models\ScheduledFollowUp;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * "Someone assigned you a follow-up" — a notice that is read and goes away,
 * deliberately not an Alert. The payload carries ids and the due date only:
 * no description and no student name (CLAUDE.md security rule 11); the client
 * resolves what it needs through the normal, authorized endpoints.
 *
 * Also sent when someone is made general responsible or responsible of a
 * stage (ClickUp 86e3qkwna): `assigned_as` tells which, and for a stage the
 * date is the stage's own due date.
 */
class FollowUpAssignedNotification extends Notification
{
    public function __construct(
        protected ScheduledFollowUp $followUp,
        protected ?User $assignedBy = null,
        protected ?FollowUpStage $stage = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'follow_up_assigned',
            'follow_up_id' => $this->followUp->id,
            'student_id' => $this->followUp->student_id,
            'due_date' => ($this->stage ?? $this->followUp)->due_date->toDateString(),
            'assigned_by_id' => $this->assignedBy?->id ?? $this->followUp->created_by_id,
            'assigned_as' => $this->stage !== null ? 'stage_responsible' : 'general_responsible',
            'stage_id' => $this->stage?->id,
        ];
    }
}
