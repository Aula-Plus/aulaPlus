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
 * {@see self::scopeVisibleToRole()} and {@see self::isVisibleToRoles()},
 * which must stay in sync (the scope filters at the DB level for the
 * dedicated list endpoints, the plain-PHP method filters an already-fetched,
 * app-cached collection for the student tracking view).
 */
#[Fillable(['author_id', 'commentable_type', 'commentable_id', 'content', 'tone', 'visible_to'])]
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
     * Restrict a query to comments visible to any of the given user's roles
     * (or with no `visible_to` restriction at all). Used by the dedicated
     * comment list endpoints, where filtering in the DB query is cheap and
     * avoids fetching rows the user can never see.
     */
    public function scopeVisibleToRole(Builder $query, User $user): Builder
    {
        $roles = $user->getRoleNames()->all();

        return $query->where(function (Builder $q) use ($roles): void {
            $q->whereNull('visible_to');

            foreach ($roles as $role) {
                $q->orWhereJsonContains('visible_to', $role);
            }
        });
    }

    /**
     * Same rule as {@see self::scopeVisibleToRole()}, evaluated in PHP against
     * an already-loaded model — used by the student tracking view, which
     * caches the raw (unfiltered) comment list and re-applies this filter on
     * every request so the cache never leaks a role-restricted comment to a
     * role that shouldn't see it.
     */
    public function isVisibleToRoles(array $roles): bool
    {
        if (empty($this->visible_to)) {
            return true;
        }

        return count(array_intersect($this->visible_to, $roles)) > 0;
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
