<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Comment;
use App\Models\CommentShare;
use App\Models\Student;
use App\Models\User;

/**
 * Authorization for «Compartir con» extracts (ClickUp 86e3qkwnv). Tenant
 * isolation and the role rule are both checked here (defence in depth over
 * SchoolScope, per CLAUDE.md).
 *
 * - Only psychopedagogy shares, and only an observation on a student that it
 *   can itself read (so never another person's "solo quien escribe" note).
 * - An extract is read by its author and its recipients only, and only while
 *   they can still see the student.
 * - Recipients are direction and/or teachers who already see the student —
 *   the same "who can see this student" rule as scheduled follow-ups
 *   ({@see ScheduledFollowUpPolicy::canFollow()}).
 */
class CommentSharePolicy
{
    public function __construct(
        protected StudentPolicy $students,
        protected ScheduledFollowUpPolicy $followUps,
    ) {}

    public function create(User $user, Comment $comment): bool
    {
        if ($user->school_id !== $comment->school_id
            || ! $user->hasRole(Role::Psychopedagogue->value)
            || $comment->commentable_type !== Student::class) {
            return false;
        }

        $student = Student::query()->find($comment->commentable_id);

        return $student !== null
            && $this->students->view($user, $student)
            && $comment->isVisibleTo($user);
    }

    public function view(User $user, CommentShare $share): bool
    {
        if ($user->school_id !== $share->school_id) {
            return false;
        }

        $student = $share->student;

        return $student !== null
            && $this->students->view($user, $student)
            && ($share->author_id === $user->id || $share->isRecipient($user));
    }

    /**
     * Whether `$recipient` may receive an extract about `$student`: an active
     * director or teacher who already sees the student. Psychopedagogy is not
     * a recipient — it already reads the original.
     */
    public function canReceive(User $recipient, Student $student): bool
    {
        return $recipient->disabled_at === null
            && $recipient->hasAnyRole([Role::Director->value, Role::Teacher->value])
            && $this->followUps->canFollow($recipient, $student);
    }
}
