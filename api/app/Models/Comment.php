<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 *
 * Psychopedagogy reads every comment except "solo quien escribe" (ClickUp
 * 86e3qkwnv): `visible_to` narrows what the *other* roles read, never what a
 * psychopedagogue reads. The scope selector offers exactly four presets —
 * everyone (`null`), {@see self::SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY},
 * {@see self::SCOPE_PSYCHOPEDAGOGY_ONLY} and `author_only` — normalized by
 * {@see self::normalizeVisibleTo()}.
 */
#[Fillable(['author_id', 'commentable_type', 'commentable_id', 'content', 'visible_to', 'visible_to_users', 'author_only'])]
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    public static array $auditableExcludeFromDiff = ['content'];

    /** «Dirección y Psicopedagogía» (replaces the old "solo dirección"). */
    public const SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY = ['director', 'psychopedagogue'];

    /** «Solo Psicopedagogía». */
    public const SCOPE_PSYCHOPEDAGOGY_ONLY = ['psychopedagogue'];

    /**
     * The `visible_to` values a new comment may be stored with (besides `null`,
     * "everyone who sees the record"). Kept sorted so they compare with `===`
     * against {@see self::normalizeVisibleTo()}'s output.
     *
     * @return list<list<string>>
     */
    public static function visibleToPresets(): array
    {
        return [self::SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY, self::SCOPE_PSYCHOPEDAGOGY_ONLY];
    }

    /**
     * Canonical form of a `visible_to` role list: `null` for "everyone"
     * (empty, or every role listed), deduplicated and sorted otherwise. The
     * old "solo dirección" (`["director"]`) maps to "Dirección y
     * Psicopedagogía" — psychopedagogy reads it anyway, so this only makes
     * the stored value say what the rule already does. Shared by the store
     * FormRequests and the data migration that rewrote existing rows.
     *
     * @param  array<int, string>|null  $roles
     * @return list<string>|null
     */
    public static function normalizeVisibleTo(?array $roles): ?array
    {
        if ($roles === null || $roles === []) {
            return null;
        }

        $roles = array_values(array_unique($roles));
        sort($roles);

        if (array_diff(Role::values(), $roles) === []) {
            return null;
        }

        if ($roles === [Role::Director->value]) {
            return self::SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY;
        }

        return $roles;
    }

    protected function casts(): array
    {
        return [
            'visible_to' => 'array',
            'visible_to_users' => 'array',
            'author_only' => 'boolean',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Optional, several per comment; none = "Sin categoría" (also every
     * comment written before categories existed).
     *
     * @return BelongsToMany<CommentCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(CommentCategory::class, 'comment_comment_category');
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
     * Cases, OR-ed together (must stay in sync with {@see self::isVisibleTo()}):
     * - the user's own comments, whatever their scope;
     * - `author_only` comments: visible only to their author, and to no one
     *   else regardless of role — this is stricter than any `visible_to` rule;
     * - every other comment (`author_only = false`), per role of the user:
     *   always visible to a psychopedagogue; for any other role, visible when
     *   it has no `visible_to` restriction or lists that role — and, when the
     *   author picked specific people of that role (`visible_to_users`,
     *   «Elegir personas», ClickUp 86e3qkwnv), only to those people.
     */
    public function scopeVisibleToRole(Builder $query, User $user): Builder
    {
        $roles = $user->getRoleNames()->all();

        return $query->where(function (Builder $outer) use ($roles, $user): void {
            $outer->where('author_id', $user->id)
                ->orWhere(function (Builder $q) use ($roles, $user): void {
                    $q->where('author_only', false)->where(function (Builder $byRole) use ($roles, $user): void {
                        // No role → no grant (an always-false group).
                        $byRole->whereRaw('1 = 0');

                        foreach ($roles as $role) {
                            $byRole->orWhere(function (Builder $grant) use ($role, $user): void {
                                if ($role !== Role::Psychopedagogue->value) {
                                    $grant->where(fn (Builder $r) => $r->whereNull('visible_to')
                                        ->orWhereJsonContains('visible_to', $role));
                                }

                                $grant->where(fn (Builder $p) => $p->whereNull('visible_to_users')
                                    ->orWhereNull("visible_to_users->{$role}")
                                    ->orWhereJsonContains("visible_to_users->{$role}", $user->id));
                            });
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
     * and «Elegir personas» need to know *who* is asking, not only their
     * roles: two users can share the `teacher` role.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->author_id === $user->id) {
            return true;
        }

        if ($this->author_only) {
            return false;
        }

        foreach ($user->getRoleNames()->all() as $role) {
            $roleAllowed = $role === Role::Psychopedagogue->value
                || empty($this->visible_to)
                || in_array($role, $this->visible_to, true);

            $people = $this->visible_to_users[$role] ?? null;

            if ($roleAllowed && ($people === null || in_array($user->id, array_map('intval', $people), true))) {
                return true;
            }
        }

        return false;
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
