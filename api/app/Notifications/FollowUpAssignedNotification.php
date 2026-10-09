<?php

namespace App\Notifications;

use App\Models\ScheduledFollowUp;
use Illuminate\Notifications\Notification;

/**
 * "Someone assigned you a follow-up" — a notice that is read and goes away,
 * deliberately not an Alert. The payload carries ids and the due date only:
 * no description and no student name (CLAUDE.md security rule 11); the client
 * resolves what it needs through the normal, authorized endpoints.
 */
class FollowUpAssignedNotification extends Notification
{
    public function __construct(protected ScheduledFollowUp $followUp) {}

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
            'due_date' => $this->followUp->due_date->toDateString(),
            'assigned_by_id' => $this->followUp->created_by_id,
        ];
    }
}
