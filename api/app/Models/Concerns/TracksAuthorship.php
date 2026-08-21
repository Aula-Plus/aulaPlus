<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Adopt this trait on any model with `created_by_id` / `deleted_by_id`
 * columns to fill them in automatically from the authenticated user, instead
 * of every controller/action having to remember to set them.
 *
 * - `creating()`: sets `created_by_id` to the current user, unless the caller
 *   already set one explicitly (e.g. a system Job attributing the record to a
 *   specific user on their behalf).
 * - `deleting()`: sets `deleted_by_id` to the current user. This fires on
 *   Eloquent soft deletes; a soft delete persists only `deleted_at` (and
 *   `updated_at`) via a raw query, bypassing `save()`/model events, so
 *   `deleted_by_id` is written the same way here rather than relying on a
 *   later `updated` event that would never fire for it.
 */
trait TracksAuthorship
{
    public static function bootTracksAuthorship(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('created_by_id') === null) {
                $model->setAttribute('created_by_id', static::tracksAuthorshipCurrentUserId());
            }
        });

        static::deleting(function (Model $model): void {
            $userId = static::tracksAuthorshipCurrentUserId();
            $model->setAttribute('deleted_by_id', $userId);
            $model->newModelQuery()->whereKey($model->getKey())->update(['deleted_by_id' => $userId]);
        });
    }

    /**
     * Same multi-guard resolution as Tenancy's — see
     * Auditable::auditableCurrentUser() for why this is duplicated rather
     * than shared.
     */
    protected static function tracksAuthorshipCurrentUserId(): ?int
    {
        return (Auth::user() ?? Auth::guard('sanctum')->user())?->id;
    }
}
