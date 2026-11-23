<?php

namespace App\Notifications;

use App\Models\MeetingSheetAction;
use Illuminate\Notifications\Notification;

/**
 * An action agreed in a meeting was left to the recipient, with its deadline
 * (P10-PLA-01, P10-DEC-08). The action itself is a task in their Inicio. The
 * payload carries ids and the deadline only, never the action's text.
 */
class MeetingActionAssignedNotification extends Notification
{
    public function __construct(protected MeetingSheetAction $action) {}

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
            'type' => 'meeting_action_assigned',
            'meeting_sheet_action_id' => $this->action->id,
            'due_date' => $this->action->due_on->toDateString(),
        ];
    }
}
