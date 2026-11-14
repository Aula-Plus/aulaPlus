<?php

namespace App\Notifications;

use App\Models\Accommodation;
use Illuminate\Notifications\Notification;

/**
 * "A new accommodation is in force for [student]" (screen 22, ClickUp
 * 1dfd3mc4bgw): how a teacher learns that psychopedagogy confirmed an
 * accommodation that reaches them, without anyone telling them. The payload
 * carries ids only — never the accommodation's type, description, barrier or
 * the student's name (CLAUDE.md rule 11, screen 22 red line: a notice says
 * what happened and links to where the role may see it). NotificationController
 * resolves the student's name at read time for a recipient who may still see
 * the student.
 */
class AccommodationConfirmedNotification extends Notification
{
    public function __construct(protected Accommodation $accommodation) {}

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
            'type' => 'accommodation_confirmed',
            'accommodation_id' => $this->accommodation->id,
            'student_id' => $this->accommodation->student_id,
        ];
    }
}
