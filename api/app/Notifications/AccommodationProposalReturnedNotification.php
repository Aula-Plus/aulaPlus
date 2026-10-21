<?php

namespace App\Notifications;

use App\Models\Accommodation;
use Illuminate\Notifications\Notification;

/**
 * "Psychopedagogy returned your accommodation proposal for corrections"
 * (ClickUp 86e3qkwkk) — a notice that is read and goes away. Ids only: no
 * proposal content, comment or student name (CLAUDE.md rule 11); the client
 * resolves what it needs through the normal, authorized endpoints.
 */
class AccommodationProposalReturnedNotification extends Notification
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
            'type' => 'accommodation_proposal_returned',
            'accommodation_id' => $this->accommodation->id,
            'student_id' => $this->accommodation->student_id,
        ];
    }
}
