<?php

namespace App\Notifications;

use App\Models\MeetingSheetFragment;
use Illuminate\Notifications\Notification;

/**
 * «Te llegó una planilla» (screen 10, P10-DEC-05/07): psychopedagogy
 * confirmed a sheet's split and its fragment about a student landed in that
 * student's file, where the recipient can read it. A notice, not an alert.
 * The payload carries ids only — never the fragment nor the student's name
 * (CLAUDE.md rule 11); NotificationController resolves the name at read
 * time for a recipient who may still see the student.
 */
class MeetingSheetFragmentNotification extends Notification
{
    public function __construct(protected MeetingSheetFragment $fragment) {}

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
            'type' => 'meeting_sheet_fragment',
            'student_id' => $this->fragment->student_id,
            'comment_id' => $this->fragment->comment_id,
        ];
    }
}
