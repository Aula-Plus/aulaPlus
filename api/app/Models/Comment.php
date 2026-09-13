<?php

namespace App\Models;

use App\Enums\CommentTone;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A multi-actor comment on a Student or Group (polymorphic — see
 * docs/prompts/04-seguimiento-institucional.md §1). `content` may hold
 * sensitive observations about a minor, so it's excluded from the audit diff
 * (CLAUDE.md security rule 11), same pattern as Accommodation/Barrier.
 *
 * `visible_to` restricts which roles can see the comment in listings (e.g. a
 * psychopedagogue can keep a comment private to psychopedagogue/director). A
 * null/empty `visible_to` means visible to every role — see
 * {@see self::scopeVisibleToRole()} and {@see self::isVisibleTo()},
 * which must stay in sync (the scope filters at the DB level for the
 * dedicated list endpoints, the plain-PHP method filters an already-fetched,
 * app-cached collection for the student tracking view).
 *
 * `author_only` is the fourth, strictest scope (docs/prompts/19-comentarios-
 * alcance.md §1): when true the comment is visible only to `author_id`,
 * regardless of `visible_to` (which is forced to null in that case by the
 * store FormRequests). It is a per-*person* restriction, not a role, so it
 * cannot be expressed through `visible_to` — that is why it lives in its own
 * column and is handled explicitly by both visibility methods below.
 */
#[Fillable(['author_id', 'commentable_type', 'commentable_id', 'content', 'tone', 'visible_to', 'author_only'])]
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    public static array $auditableExcludeFromDiff = ['content'];

    protected function casts(): array
    {
        return [
            'tone' => CommentTone::class,
            'visible_to' => 'array',
            'author_only' => 'boolean',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Restrict a query to comments the given user is allowed to see. Used by
     * the dedicated comment list endpoints, where filtering in the DB query is
     * cheap and avoids fetching rows the user can never see.
     *
     * Two independent cases, OR-ed together (must stay in sync with
     * {@see self::isVisibleTo()}):
     * - `author_only` comments: visible only to their author, and to no one
     *   else regardless of role — this is stricter than any `visible_to` rule.
     * - every other comment (`author_only = false`): the role-based rule —
     *   visible when it has no `visible_to` restriction or lists one of the
     *   user's roles.
     */
    public function scopeVisibleToRole(Builder $query, User $user): Builder
    {
        $roles = $user->getRoleNames()->all();

        return $query->where(function (Builder $outer) use ($roles, $user): void {
            $outer->where(function (Builder $q) use ($user): void {
                $q->where('author_only', true)
                    ->where('author_id', $user->id);
            })->orWhere(function (Builder $q) use ($roles): void {
                $q->where('author_only', false)
                    ->where(function (Builder $inner) use ($roles): void {
                        $inner->whereNull('visible_to');

                        foreach ($roles as $role) {
                            $inner->orWhereJsonContains('visible_to', $role);
                        }
                    });
            });
        });
    }

    /**
     * Same rule as {@see self::scopeVisibleToRole()}, evaluated in PHP against
     * an already-loaded model — used by the student tracking view, which
     * caches the raw (unfiltered) comment list and re-applies this filter on
     * every request so the cache never leaks a restricted comment to a user
     * who shouldn't see it.
     *
     * Takes the whole `User` (not just their roles) because `author_only`
     * needs to know *who* is asking, not only their roles: "only the author"
     * isolates a person, and two users can share the `teacher` role.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->author_only) {
            return $this->author_id === $user->id;
        }

        if (empty($this->visible_to)) {
            return true;
        }

        return count(array_intersect($this->visible_to, $user->getRoleNames()->all())) > 0;
    }

    /**
     * The default `visible_to` when the author doesn't restrict it: every
     * role in the platform.
     *
     * @return list<string>
     */
    public static function allRoles(): array
    {
        return Role::values();
    }
}
