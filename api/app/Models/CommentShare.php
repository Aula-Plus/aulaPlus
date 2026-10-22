<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Notifications\CommentSharedNotification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An *extract* of a student observation that psychopedagogy shares with
 * direction and/or teachers who see the student (ClickUp 86e3qkwnv,
 * «Compartir con»). It is new text written by the sharer, linked to the
 * original `comment_id`: recipients read only this extract, never the
 * original, which keeps its own scope. Sharing one observation opens nothing
 * else.
 *
 * Extracts are deliberately not comments: they don't count toward anyone's
 * trend mark (the recipient would otherwise be counting something written for
 * them, not observed by the team) and never appear in the comment endpoints.
 *
 * `content` may hold sensitive observations about a minor, so it is excluded
 * from the audit diff (CLAUDE.md security rule 11), same as Comment.
 *
 * Each recipient is notified ({@see CommentSharedNotification}).
 */
#[Fillable(['comment_id', 'student_id', 'author_id', 'content'])]
class CommentShare extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['content'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'comment_share_user')->withTimestamps();
    }

    public function isRecipient(User $user): bool
    {
        return $this->recipients()->whereKey($user->id)->exists();
    }
}
