<?php

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Notifications\Notification;

/**
 * "An alert you have reached its deadline without a way out" (ClickUp
 * 86e3jpzdp, review of 14/10) — a notice for whoever has the alert now, not a
 * new alert. The payload carries ids and the deadline only: no description
 * and no student name (CLAUDE.md security rule 11); the client resolves what
 * it needs through the normal, authorized endpoints.
 */
class AlertOverdueNotification extends Notification
{
    public function __construct(protected Alert $alert) {}

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
            'type' => 'alert_overdue',
            'alert_id' => $this->alert->id,
            'student_id' => $this->alert->student_id,
            'due_date' => $this->alert->due_on?->toDateString(),
        ];
    }
}
