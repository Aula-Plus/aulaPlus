<?php

namespace App\Notifications;

use App\Models\CommentShare;
use Illuminate\Notifications\Notification;

/**
 * "Psicopedagogía te compartió un extracto sobre [alumno]" (ClickUp
 * 86e3qkwnv): without it a recipient would never know there is an extract to
 * read. The payload carries ids only — never the extract nor the student's
 * name (CLAUDE.md security rule 11); NotificationController resolves the name
 * at read time for a recipient who may see the student.
 */
class CommentSharedNotification extends Notification
{
    public function __construct(protected CommentShare $share) {}

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
            'type' => 'comment_shared',
            'comment_share_id' => $this->share->id,
            'student_id' => $this->share->student_id,
            'shared_by_id' => $this->share->author_id,
        ];
    }
}
